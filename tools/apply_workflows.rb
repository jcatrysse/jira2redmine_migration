# Writes the workflow plan of tools/workflow_plan.php into Redmine (Jan, decision 13). Run in the Redmine root:
#   bundle exec rails runner /path/to/apply_workflows.rb PLAN.json                  preview, changes nothing
#   bundle exec rails runner /path/to/apply_workflows.rb PLAN.json --confirm-push   apply
# Repeatable: a second run finds nothing to change.
# - transitions: for every tracker and role (and project) of the plan, exactly the plan's transitions.
#   Mode "project" (Jan, 2026-10-14) needs redmine_project_workflows: each project gets its own Jira workflow
#   (rows with project_id; on the plugin's 7.0 branch also a project_workflow_scopes row, made with its
#   ScopeWriter, without copying the generic rules), the union cleaned by Jan is the global workflow (rows
#   without project). Mode "union" writes only the global workflow. A tracker and role of a project without
#   approved transitions is an own, empty workflow on 7.0; the plugin's 5.1 version cannot store an empty
#   one, so there that role follows the global workflow (counted in the output).
#   This replaces the temporary open workflows of the "workflows" step; the global tracker and role pairs it
#   owns now are taken out of tmp/j2r_added_workflows.json, so "restore" does not remove them.
# - field permissions: added or updated, none removed (global rules);
# - redmine_custom_workflows scripts named "j2r: ...": created, updated, and removed when no longer planned.
require 'json'
require 'set'

plan = JSON.parse(File.read(ARGV.fetch(0)))
confirm = ARGV.include?('--confirm-push')
mode = plan.fetch('mode', 'union')
plugin = WorkflowRule.column_names.include?('project_id')
scopes = plugin && ActiveRecord::Base.connection.table_exists?(:project_workflow_scopes)
if mode == 'project' && !plugin
  abort 'Mode "project" needs the plugin redmine_project_workflows; install it or set migration.workflows.mode to "union".'
end

desired = Hash.new { |hash, key| hash[key] = Set.new }
plan['managed'].each { |project_id, tracker_id, role_id| desired[[project_id, tracker_id, role_id]] }
plan['transitions'].each do |t|
  desired[[t['project_id'], t['tracker_id'], t['role_id']]] << [t['old_status_id'], t['new_status_id'], t['author'], t['assignee']]
end
add = []
remove = []
empty_on_51 = 0
missing_scopes = []
desired.each do |(project_id, tracker_id, role_id), wanted|
  where = { tracker_id: tracker_id, role_id: role_id }
  where[:project_id] = project_id if plugin
  have = Hash.new { |hash, key| hash[key] = [] }
  WorkflowTransition.where(where).pluck(:id, :old_status_id, :new_status_id, :author, :assignee).each do |id, from, to, author, assignee|
    have[[from, to, author, assignee]] << id
  end
  have.each { |key, ids| remove.concat(wanted.include?(key) ? ids.drop(1) : ids) }
  (wanted - have.keys).each do |from, to, author, assignee|
    row = { type: 'WorkflowTransition', tracker_id: tracker_id, role_id: role_id, old_status_id: from, new_status_id: to,
            author: author, assignee: assignee }
    row[:project_id] = project_id if plugin
    add << row
  end
  next if project_id.nil?

  empty_on_51 += 1 if wanted.empty? && !scopes
  if scopes && !ProjectWorkflowScope.where(project_id: project_id, tracker_id: tracker_id, role_id: role_id,
                                           rule_type: 'transitions').exists?
    missing_scopes << [project_id, tracker_id, role_id]
  end
end

permissions = plan['field_permissions'].filter_map do |p|
  where = { tracker_id: p['tracker_id'], role_id: p['role_id'], old_status_id: p['old_status_id'], field_name: p['field_name'] }
  where[:project_id] = nil if plugin
  rule = WorkflowPermission.find_or_initialize_by(where)
  next if rule.persisted? && rule.rule == p['rule']

  rule.rule = p['rule']
  rule
end

scripts = []
stale = []
if defined?(CustomWorkflow)
  plan['scripts'].each do |s|
    workflow = CustomWorkflow.find_or_initialize_by(name: s['name'])
    changed = workflow.new_record? || workflow.before_save != s['script'] || !workflow.active ||
              workflow.project_ids.sort != s['project_ids'].sort
    scripts << [workflow, s] if changed
  end
  stale = CustomWorkflow.where('name LIKE ?', 'j2r: %').where.not(name: plan['scripts'].map { |s| s['name'] }).to_a
elsif plan['scripts'].any?
  puts "[warn] redmine_custom_workflows is not installed: #{plan['scripts'].size} script(s) not applied"
end

projects = desired.keys.map(&:first).compact.uniq.size
puts "#{confirm ? 'Applying' : 'Preview'} (#{mode}): transitions +#{add.size} -#{remove.size} over #{desired.size} " \
     "tracker/role set(s), #{projects} project(s) with their own workflow; scopes to create #{missing_scopes.size}; " \
     "#{empty_on_51} empty project set(s) follow the global workflow (5.1 plugin); field permissions #{permissions.size}; " \
     "scripts #{scripts.size} to write, #{stale.size} to remove; " \
     "waiting for review: #{plan['waiting'].map { |k, v| "#{k} #{v}" }.join(', ')}"
exit unless confirm

if missing_scopes.any?
  # the plugin's own writer: an own workflow without copying the generic rules (they follow below)
  admin = User.where(admin: true, status: User::STATUS_ACTIVE).order(:id).first
  missing_scopes.each do |project_id, tracker_id, role_id|
    RedmineProjectWorkflows::Services::ScopeWriter.enable(project_ids: [project_id], tracker_ids: [tracker_id],
                                                         role_ids: [role_id], rule_type: 'transitions',
                                                         copy_generic: false, user: admin)
  end
end
ActiveRecord::Base.transaction do
  WorkflowTransition.where(id: remove).delete_all if remove.any?
  add.each_slice(5000) { |slice| WorkflowRule.insert_all(slice) }
  permissions.each(&:save!)
  scripts.each do |workflow, s|
    workflow.assign_attributes(observable: 'issue', before_save: s['script'], is_for_all: false, active: true,
                               description: 'Jira post function "assign to the current user" (jira2redmine, decision 13)')
    workflow.project_ids = s['project_ids']
    workflow.save!
  end
  stale.each(&:destroy)
end
RedmineProjectWorkflows::Services::Resolver.reset_cache! if scopes

# the global pairs the plan owns now: "restore" must not take their transitions away
added_file = Rails.root.join('tmp', 'j2r_added_workflows.json')
if File.exist?(added_file)
  owned = desired.keys.select { |project_id, _, _| project_id.nil? }.map { |_, tracker_id, role_id| [tracker_id, role_id] }.to_set
  kept = JSON.parse(File.read(added_file)).reject { |tracker_id, role_id, _, _| owned.include?([tracker_id, role_id]) }
  File.write(added_file, JSON.generate(kept))
end
puts 'applied'
