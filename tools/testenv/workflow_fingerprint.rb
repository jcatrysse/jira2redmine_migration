# The workflows as users get them (test runs only): for every project with its own workflow
# (redmine_project_workflows), tracker and role, and every status (0: a new issue), the statuses Redmine offers.
#   OUT=answers.json bundle exec rails runner workflow_fingerprint.rb
# Two runs compare a plugin upgrade before and after. Users, memberships and issues are made in a
# transaction that is rolled back.
require 'json'

sets = WorkflowTransition.where.not(project_id: nil).distinct.pluck(:project_id, :tracker_id, :role_id)
if ActiveRecord::Base.connection.table_exists?(:project_workflow_scopes)
  sets |= ProjectWorkflowScope.where(rule_type: 'transitions').pluck(:project_id, :tracker_id, :role_id)
end
statuses = IssueStatus.sorted.to_a
answers = {}
ActiveRecord::Base.transaction do
  users = {}
  sets.sort.each do |project_id, tracker_id, role_id|
    project = Project.find(project_id)
    user = users[[project_id, role_id]] ||= begin
      u = User.new(firstname: 'Fingerprint', lastname: 'Proof', mail: "fingerprint.#{project_id}.#{role_id}@example.invalid")
      u.login = "j2r_fingerprint_#{project_id}_#{role_id}"
      u.password = u.password_confirmation = 'Proof-2026-only'
      u.save!
      Member.create!(project: project, user: u, role_ids: [role_id])
      u
    end
    fresh = Issue.new(project: project, tracker_id: tracker_id, author: user, subject: 'fingerprint')
    answers["#{project_id}|#{tracker_id}|#{role_id}|0"] = fresh.new_statuses_allowed_to(user).map(&:id).sort
    issue = Issue.new(project: project, tracker_id: tracker_id, author: user, subject: 'fingerprint', status: statuses.first)
    issue.save!(validate: false)
    statuses.each do |status|
      issue.update_column(:status_id, status.id)
      answers["#{project_id}|#{tracker_id}|#{role_id}|#{status.id}"] = Issue.find(issue.id).new_statuses_allowed_to(user).map(&:id).sort
    end
  end
  raise ActiveRecord::Rollback
end
File.write(ENV.fetch('OUT'), JSON.pretty_generate(answers.sort.to_h))
puts "sets=#{sets.size} answers=#{answers.size} offered=#{answers.values.sum(&:size)}"
