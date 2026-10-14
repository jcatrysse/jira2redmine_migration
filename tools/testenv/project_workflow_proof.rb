# Proof of Jan's per-project workflows (decision 13, redmine_project_workflows), test runs only:
#   J2R_TRACKER=Task J2R_FROM='In Progress' J2R_TO='Create RMA' J2R_WITHOUT=geos J2R_WITH=labo J2R_ROLE=Developer \
#     bundle exec rails runner project_workflow_proof.rb
# A user with the role in both projects: the status J2R_TO is offered from J2R_FROM in J2R_WITH's tracker and not
# in J2R_WITHOUT's. The user and memberships are made in a transaction that is rolled back.
tracker = Tracker.find_by!(name: ENV.fetch('J2R_TRACKER'))
from = IssueStatus.find_by!(name: ENV.fetch('J2R_FROM'))
to = IssueStatus.find_by!(name: ENV.fetch('J2R_TO'))
role = Role.find_by!(name: ENV.fetch('J2R_ROLE', 'Developer'))
offered = {}
ActiveRecord::Base.transaction do
  user = User.new(firstname: 'Workflow', lastname: 'Proof', mail: 'workflow.proof@example.invalid')
  user.login = 'j2r_workflow_proof'
  user.password = user.password_confirmation = 'Proof-2026-only'
  user.save!
  %w[J2R_WITHOUT J2R_WITH].each do |key|
    project = Project.find_by!(identifier: ENV.fetch(key))
    Member.create!(project: project, user: user, role_ids: [role.id])
    # an existing issue in J2R_FROM, as on the edit form
    issue = Issue.new(project: project, tracker: tracker, author: user, subject: 'proof', status: from)
    issue.save!(validate: false)
    issue.update_column(:status_id, from.id)
    offered[key] = Issue.find(issue.id).new_statuses_allowed_to(user).map(&:name)
  end
  raise ActiveRecord::Rollback
end
puts "#{tracker.name}, #{role.name}, from #{from.name}: #{ENV['J2R_WITHOUT']} offers #{to.name}=#{offered['J2R_WITHOUT'].include?(to.name)} " \
     "(#{offered['J2R_WITHOUT'].size} statuses), #{ENV['J2R_WITH']} offers #{to.name}=#{offered['J2R_WITH'].include?(to.name)} " \
     "(#{offered['J2R_WITH'].size} statuses)"
