# Proof of Jan's decisions 34 and 35 on a Redmine that is not empty (test instances only):
#   J2R_PROOF=setup bundle exec rails runner existing_redmine_proof.rb   before the run, on the baseline
#   J2R_PROOF=check bundle exec rails runner existing_redmine_proof.rb   after the run (workflows and restore)
# setup gives the Redmine what an existing one has: a tracker "Task" (Jira has an issue type of that name) with
# its own global workflow, used by a project the migration does not touch. check prints that workflow's
# fingerprint (it must equal setup's: decision 34), the migrated projects' own workflows for that tracker, the
# priorities (decision 35: none added) and the issues of the existing project.
require 'digest'

PROOF_TRACKER = 'Task'.freeze
global_rules = lambda do |tracker|
  scope = WorkflowTransition.where(tracker_id: tracker.id)
  scope = scope.where(project_id: nil) if WorkflowRule.column_names.include?('project_id')
  scope.order(:role_id, :old_status_id, :new_status_id)
       .pluck(:role_id, :old_status_id, :new_status_id, :author, :assignee)
end
fingerprint = lambda do |tracker|
  rows = global_rules.call(tracker)
  "#{rows.size} rows, sha256 #{Digest::SHA256.hexdigest(rows.inspect)[0, 16]}"
end
priorities = -> { IssuePriority.sorted.map { |p| "#{p.name}|#{p.position}" }.join(' ') }

case ENV.fetch('J2R_PROOF')
when 'setup'
  abort "#{PROOF_TRACKER} exists already: run setup on the baseline" if Tracker.exists?(name: PROOF_TRACKER)
  statuses = IssueStatus.where(name: ['New', 'In Progress', 'Resolved']).index_by(&:name)
  tracker = Tracker.create!(name: PROOF_TRACKER, default_status: statuses.fetch('New'))
  Role.where(name: %w[Manager Developer]).find_each do |role|
    [%w[New In\ Progress], ['In Progress', 'Resolved']].each do |from, to|
      WorkflowTransition.create!(tracker: tracker, role: role, old_status: statuses.fetch(from), new_status: statuses.fetch(to))
    end
  end
  Project.create!(name: 'Existing project', identifier: 'existing-project', tracker_ids: [tracker.id])
  puts "setup: tracker #{PROOF_TRACKER} ##{tracker.id} global workflow #{fingerprint.call(tracker)}; priorities #{priorities.call}"
when 'check'
  tracker = Tracker.find_by!(name: PROOF_TRACKER)
  existing = Project.find_by!(identifier: 'existing-project')
  own = if WorkflowRule.column_names.include?('project_id')
          WorkflowTransition.where(tracker_id: tracker.id).where.not(project_id: nil).group(:project_id).count
                            .map { |project_id, count| "#{Project.find(project_id).identifier} #{count}" }.sort.join(', ')
        else
          'no project_id column'
        end
  puts "check: tracker #{PROOF_TRACKER} ##{tracker.id} global workflow #{fingerprint.call(tracker)}; own workflows #{own}; " \
       "priorities #{priorities.call}; existing project issues #{existing.issues.count}, trackers #{existing.trackers.map(&:name).join(',')}"
end
