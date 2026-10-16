# The manual steps around the migration (CHANGELOG, plan M8, docs/PRODUCTION.md). Run with
#   J2R_STEP=workflows|members|restore J2R_PROJECTS=geos,sis,oac bundle exec rails runner manual_steps.rb
# workflows: every transition open, new issues included, on every tracker for the roles J2R_WORKFLOW_ROLES names
#            (a JSON list from migration.workflows.open_workflow_roles, decision 33; Manager and Developer
#            without it); "open the workflows", reset after the migration. A role this Redmine lacks, or one
#            without Add or Edit issues (Redmine ignores its workflow), stops the step; "Migration (temporary)"
#            is created here when listed. Watchers need view_watched_issues (redmine_view_issue_description),
#            given to those roles; parent priority independent (B13).
# members:   activate locked users, flagged with preference j2r_relock ("activate locked users"), and make
#            every migrated user a member of every migrated project (J2R_PROJECTS) with the temporary role
#            "Migration (temporary)": every permission, assignable (Jan, decision 12). It comes on top of the
#            final memberships of 04 (B31 option a), so Redmine accepts every historical assignee and watcher.
#            J2R_USER_IDS_FILE (one Redmine user id per line, from migration_mapping_users) limits it to the
#            migrated users; without it every user but the admin. Run after 04's push, before 08.
# restore:   undo after the migration: remove exactly the workflow transitions "workflows" added (listed in
#            tmp/j2r_added_workflows.json), the temporary role and every membership left without a role (the
#            final memberships of 04 stay), and lock again the users "members" activated. Watchers stay:
#            Redmine prunes watchers only with `rake redmine:watchers:prune`, never run it after the migration.
step = ENV.fetch('J2R_STEP')
TEMPORARY_ROLE = 'Migration (temporary)'.freeze
# decision 12: every permission, assignable; held by the migrated users in the migrated projects until restore
temporary_role = lambda do
  temporary = Role.find_or_initialize_by(name: TEMPORARY_ROLE)
  if temporary.new_record?
    temporary.assignable = true
    temporary.issues_visibility = 'all'
    temporary.users_visibility = 'all'
    temporary.time_entries_visibility = 'all'
    temporary.permissions = Redmine::AccessControl.permissions.reject(&:public?).map(&:name)
    temporary.save!
  end
  temporary
end

case step
when 'workflows'
  names = JSON.parse(ENV['J2R_WORKFLOW_ROLES'].presence || '["Manager", "Developer"]')
  abort 'J2R_WORKFLOW_ROLES names no role (migration.workflows.open_workflow_roles)' if names.empty?
  temporary_role.call if names.include?(TEMPORARY_ROLE)
  roles = Role.where(name: names).to_a
  missing = names - roles.map(&:name)
  abort "Roles named in migration.workflows.open_workflow_roles that this Redmine does not have: #{missing.join(', ')}" if missing.any?
  ignored = roles.reject(&:consider_workflow?).map(&:name)
  abort "Roles without Add issues or Edit issues (Redmine ignores their workflow): #{ignored.join(', ')}" if ignored.any?
  statuses = IssueStatus.pluck(:id)
  existing = WorkflowTransition.pluck(:tracker_id, :role_id, :old_status_id, :new_status_id).to_set
  added_file = Rails.root.join('tmp', 'j2r_added_workflows.json')
  previous = File.exist?(added_file) ? JSON.parse(File.read(added_file)) : []
  # B82: until restore every transition the list names exists (an earlier pass of this step added it); one this
  # Redmine lacks means the list is left from another state of the database (a reset, a restore without the file),
  # and restore would remove what it names, transitions Redmine had before the migration included
  stale = previous.reject { |key| existing.include?(key) }
  abort "#{added_file} names #{stale.size} of #{previous.size} transitions this Redmine does not have: it is left from " \
        'another run. Restore removes what it names: delete it only if no restore of that run is pending.' if stale.any?
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
  keys = rows.map { |r| [r[:tracker_id], r[:role_id], r[:old_status_id], r[:new_status_id]] }
  File.write(added_file, JSON.generate(previous + keys))
  # Decided by Jan (B13): a parent keeps its own priority, as in Jira.
  Setting.parent_issue_priority = 'independent'
  # B38 (Jan confirmed option a, 2026-10-14): Jira parents can be in another project (GEOS issues under WIKI epics).
  Setting.cross_project_subtasks = 'system'
  # B39 (Jan confirmed, with B38): Jira links issues across projects (29 links in the WIKI run). Both stay on after the run.
  Setting.cross_project_issue_relations = '1'
  perms = Redmine::AccessControl.permissions.map(&:name) & %i[view_watched_issues view_issue_description]
  roles.each { |role| perms.each { |p| role.add_permission!(p) } }
  puts "workflow transitions created=#{created} roles=#{roles.map(&:name).sort.inspect} permissions=#{perms.inspect} cross_project_subtasks=#{Setting.cross_project_subtasks} cross_project_issue_relations=#{Setting.cross_project_issue_relations}"
when 'members'
  temporary = temporary_role.call
  users = User.where(type: 'User').where.not(id: 1)
  ids_file = ENV['J2R_USER_IDS_FILE']
  users = users.where(id: File.readlines(ids_file).map(&:to_i)) if ids_file.present?
  projects = Project.where(identifier: ENV.fetch('J2R_PROJECTS').split(',')).to_a
  activated = 0
  added = 0
  users.find_each do |user|
    if user.locked?
      user.pref[:j2r_relock] = '1'
      user.pref.save!
      user.update_column(:status, User::STATUS_ACTIVE)
      activated += 1
    end
    projects.each do |project|
      member = Member.find_or_initialize_by(project_id: project.id, user_id: user.id)
      next if member.persisted? && member.member_roles.any? { |mr| mr.role_id == temporary.id && mr.inherited_from.nil? }
      if member.new_record?
        member.role_ids = [temporary.id]
        member.save!
      else
        MemberRole.create!(member: member, role: temporary)
      end
      added += 1
    end
  end
  puts "activated=#{activated} temporary memberships=#{added} projects=#{projects.size} members=#{Member.count}"
when 'restore'
  added_file = Rails.root.join('tmp', 'j2r_added_workflows.json')
  removed = 0
  if File.exist?(added_file)
    # one query per tracker, role and 500 status pairs (a query per transition took minutes for 112,767)
    JSON.parse(File.read(added_file)).group_by { |tracker_id, role_id, _, _| [tracker_id, role_id] }.each do |(tracker_id, role_id), keys|
      keys.each_slice(500) do |slice|
        pairs = slice.map { |_, _, from, to| "(#{from.to_i},#{to.to_i})" }.join(',')
        scope = WorkflowTransition.where(tracker_id: tracker_id, role_id: role_id)
        # the step opened global rules only: a project's own workflow (redmine_project_workflows) stays
        scope = scope.where(project_id: nil) if WorkflowRule.column_names.include?('project_id')
        removed += scope.where("(old_status_id, new_status_id) IN (#{pairs})").delete_all
      end
    end
    File.delete(added_file)
  end
  # the temporary role (decision 12): its member roles, then the memberships left without a role
  # (MemberRole's own callback), then the role; the final memberships of 04 keep their roles
  temporary_removed = 0
  temporary = Role.find_by(name: TEMPORARY_ROLE)
  if temporary
    MemberRole.where(role_id: temporary.id, inherited_from: nil).find_each do |member_role|
      member_role.destroy
      temporary_removed += 1
    end
    temporary.reload.destroy
  end
  relocked = 0
  UserPreference.find_each do |pref|
    next unless pref[:j2r_relock] == '1'
    pref.user.update_column(:status, User::STATUS_LOCKED)
    pref.others.delete(:j2r_relock)
    pref.save!
    relocked += 1
  end
  puts "workflow transitions removed=#{removed} temporary member roles removed=#{temporary_removed} members=#{Member.count} users locked again=#{relocked}"
end
