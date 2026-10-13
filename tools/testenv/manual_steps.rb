# Stand-in, for the test runs only, for the manual steps of today's workflow (CHANGELOG, plan M8). Run with
#   J2R_STEP=workflows|members|restore J2R_PROJECTS=geos,sis,oac bundle exec rails runner manual_steps.rb
# workflows: every transition open for Manager and Developer on every tracker, new issues included
#            ("open the workflows", reset after the migration); watchers need view_watched_issues
#            (redmine_view_issue_description), given to both roles; parent priority independent (B13).
# members:   activate locked users, flagged with preference j2r_relock ("activate locked users"), and add
#            every user to the test projects as Developer: Jira grants project access through permission
#            schemes, which 04 does not read (04 migrates project role actors only). Run after 04.
# restore:   undo after the migration: remove exactly the workflow transitions "workflows" added (listed in
#            tmp/j2r_added_workflows.json) and lock again the users "members" activated.
step = ENV.fetch('J2R_STEP')
roles = Role.where(name: %w[Manager Developer]).to_a

case step
when 'workflows'
  statuses = IssueStatus.pluck(:id)
  existing = WorkflowTransition.pluck(:tracker_id, :role_id, :old_status_id, :new_status_id).to_set
  rows = []
  Tracker.pluck(:id).each do |tracker_id|
    roles.each do |role|
      ([0] + statuses).each do |from|
        statuses.each do |to|
          next if from == to || existing.include?([tracker_id, role.id, from, to])
          rows << { type: 'WorkflowTransition', tracker_id: tracker_id, role_id: role.id, old_status_id: from,
                    new_status_id: to, assignee: false, author: false }
        end
      end
    end
  end
  rows.each_slice(5000) { |slice| WorkflowRule.insert_all(slice) }
  created = rows.size
  added_file = Rails.root.join('tmp', 'j2r_added_workflows.json')
  previous = File.exist?(added_file) ? JSON.parse(File.read(added_file)) : []
  keys = rows.map { |r| [r[:tracker_id], r[:role_id], r[:old_status_id], r[:new_status_id]] }
  File.write(added_file, JSON.generate(previous + keys))
  # Decided by Jan (B13): a parent keeps its own priority, as in Jira.
  Setting.parent_issue_priority = 'independent'
  # B38 (open for Jan, option a): Jira parents can be in another project (GEOS issues under WIKI epics).
  Setting.cross_project_subtasks = 'system'
  perms = Redmine::AccessControl.permissions.map(&:name) & %i[view_watched_issues view_issue_description]
  roles.each { |role| perms.each { |p| role.add_permission!(p) } }
  puts "workflow transitions created=#{created} permissions=#{perms.inspect} cross_project_subtasks=#{Setting.cross_project_subtasks}"
when 'members'
  role = Role.find_by!(name: 'Developer')
  projects = Project.where(identifier: ENV.fetch('J2R_PROJECTS').split(',')).to_a
  activated = 0
  added = 0
  User.where(type: 'User').where.not(id: 1).find_each do |user|
    if user.locked?
      user.pref[:j2r_relock] = '1'
      user.pref.save!
      user.update_column(:status, User::STATUS_ACTIVE)
      activated += 1
    end
    projects.each do |project|
      next if Member.where(user_id: user.id, project_id: project.id).exists?
      member = Member.new(user: user, project: project)
      member.role_ids = [role.id]
      member.save!
      added += 1
    end
  end
  puts "activated=#{activated} memberships=#{added}"
when 'restore'
  added_file = Rails.root.join('tmp', 'j2r_added_workflows.json')
  removed = 0
  if File.exist?(added_file)
    JSON.parse(File.read(added_file)).each_slice(1000) do |slice|
      slice.each do |tracker_id, role_id, from, to|
        removed += WorkflowTransition.where(tracker_id: tracker_id, role_id: role_id, old_status_id: from, new_status_id: to).delete_all
      end
    end
    File.delete(added_file)
  end
  relocked = 0
  UserPreference.find_each do |pref|
    next unless pref[:j2r_relock] == '1'
    pref.user.update_column(:status, User::STATUS_LOCKED)
    pref.others.delete(:j2r_relock)
    pref.save!
    relocked += 1
  end
  puts "workflow transitions removed=#{removed} users locked again=#{relocked}"
end
