# Proof of Jan's decision 14, option G (a group Jira limits transitions to gets its own role), test runs only:
#   J2R_PROJECT=doc J2R_GROUP='DOC Support' J2R_TRACKER=Task J2R_FROM=New J2R_TO='In Progress' \
#     bundle exec rails runner group_role_proof.rb
# Two members of the project that are no admins: one in the Redmine group J2R_GROUP (it holds the group's own role
# "<group> (workflow)"), one outside it holding a role the first holds too. On an issue of the tracker in J2R_FROM the
# first is offered J2R_TO, the second not. The issue is made in a transaction that is rolled back; users show by id.
project = Project.find_by!(identifier: ENV.fetch('J2R_PROJECT'))
group = Group.find_by!(lastname: ENV.fetch('J2R_GROUP'))
tracker = project.trackers.find_by!(name: ENV.fetch('J2R_TRACKER'))
from = IssueStatus.find_by!(name: ENV.fetch('J2R_FROM'))
to = IssueStatus.find_by!(name: ENV.fetch('J2R_TO'))
own = Role.find_by!(name: format(ENV.fetch('J2R_ROLE_NAME', '%s (workflow)'), group.lastname))

inside = group.users.reject(&:admin?).find { |u| u.roles_for_project(project).include?(own) }
abort "no member of #{group.lastname} holds #{own.name} in #{project.identifier}" unless inside
shared = inside.roles_for_project(project) - [own]
# every user member, locked ones too (Project#users holds active users only)
members = Member.where(project_id: project.id).includes(:principal).map(&:principal).select { |p| p.is_a?(User) }
outside = members.reject(&:admin?).find do |u|
  !group.users.include?(u) && (u.roles_for_project(project) & shared).any?
end
abort "no member outside #{group.lastname} holds #{shared.map(&:name).join(' or ')}" unless outside

offered = {}
ActiveRecord::Base.transaction do
  issue = Issue.new(project: project, tracker: tracker, author: inside, subject: 'proof', status: from)
  issue.save!(validate: false)
  Issue.where(id: issue.id).update_all(status_id: from.id)
  fresh = Issue.find(issue.id)
  [inside, outside].each { |u| offered[u.id] = fresh.new_statuses_allowed_to(u).map(&:name) }
  raise ActiveRecord::Rollback
end
[[inside, 'in the group'], [outside, 'outside the group']].each do |user, where|
  roles = user.roles_for_project(project).map(&:name).sort.join(', ')
  puts "#{project.identifier} #{tracker.name} from #{from.name}, user ##{user.id} #{where} (#{roles}): " \
       "#{to.name} offered=#{offered[user.id].include?(to.name)} (#{offered[user.id].size} statuses)"
end
puts "proof: #{offered[inside.id].include?(to.name) && !offered[outside.id].include?(to.name) ? 'PASS' : 'FAIL'}"
