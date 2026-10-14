# Writes the workflow plan of tools/workflow_plan.php into Redmine (Jan, decision 13). Run in the Redmine root:
#   bundle exec rails runner /path/to/apply_workflows.rb PLAN.json                  preview, changes nothing
#   bundle exec rails runner /path/to/apply_workflows.rb PLAN.json --confirm-push   apply
# Repeatable: a second run finds nothing to change.
# - transitions: for every tracker and role of the plan, exactly the plan's transitions (generic rules, also
#   with redmine_project_workflows); this replaces the temporary open workflows of the "workflows" step, and the
#   pairs it owns now are taken out of tmp/j2r_added_workflows.json, so "restore" does not remove them;
# - field permissions: added or updated, none removed;
# - redmine_custom_workflows scripts named "j2r: ...": created, updated, and removed when no longer planned.
require 'json'
require 'set'

plan = JSON.parse(File.read(ARGV.fetch(0)))
confirm = ARGV.include?('--confirm-push')
generic = WorkflowRule.column_names.include?('project_id') ? { project_id: nil } : {}

desired = Hash.new { |hash, key| hash[key] = Set.new }
plan['managed'].each { |tracker_id, role_id| desired[[tracker_id, role_id]] }
plan['transitions'].each do |t|
  desired[[t['tracker_id'], t['role_id']]] << [t['old_status_id'], t['new_status_id'], t['author'], t['assignee']]
end
add = []
remove = []
desired.each do |(tracker_id, role_id), wanted|
  have = Hash.new { |hash, key| hash[key] = [] }
  WorkflowTransition.where(generic.merge(tracker_id: tracker_id, role_id: role_id)).
    pluck(:id, :old_status_id, :new_status_id, :author, :assignee).each do |id, from, to, author, assignee|
    have[[from, to, author, assignee]] << id
  end
  have.each { |key, ids| remove.concat(wanted.include?(key) ? ids.drop(1) : ids) }
  (wanted - have.keys).each do |from, to, author, assignee|
    add << { type: 'WorkflowTransition', tracker_id: tracker_id, role_id: role_id, old_status_id: from, new_status_id: to,
             author: author, assignee: assignee }.merge(generic)
  end
end

permissions = plan['field_permissions'].filter_map do |p|
  rule = WorkflowPermission.find_or_initialize_by(generic.merge(tracker_id: p['tracker_id'], role_id: p['role_id'],
                                                                 old_status_id: p['old_status_id'], field_name: p['field_name']))
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

puts "#{confirm ? 'Applying' : 'Preview'}: transitions +#{add.size} -#{remove.size} over #{desired.size} tracker/role pair(s); " \
     "field permissions #{permissions.size}; scripts #{scripts.size} to write, #{stale.size} to remove; " \
     "waiting for review: #{plan['waiting'].map { |k, v| "#{k} #{v}" }.join(', ')}"
exit unless confirm

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

# the pairs the plan owns now: "restore" must not take their transitions away
added_file = Rails.root.join('tmp', 'j2r_added_workflows.json')
if File.exist?(added_file)
  owned = desired.keys.to_set
  kept = JSON.parse(File.read(added_file)).reject { |tracker_id, role_id, _, _| owned.include?([tracker_id, role_id]) }
  File.write(added_file, JSON.generate(kept))
end
puts 'applied'
