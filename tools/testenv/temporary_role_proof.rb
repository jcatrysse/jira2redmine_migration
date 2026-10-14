# Proof of Jan's decision 12 after `restore` (test runs only), run with
#   J2R_PROJECTS=geos,sis,oac bundle exec rails runner temporary_role_proof.rb
# (a) watchers who are no member of the project any more are still watchers; (b) an issue whose assignee
# is no member any more can be edited and saved when the assignee is not touched, while assigning that
# user anew is refused (Redmine validates an assignee only when it changes). Nothing is kept: (b) runs in
# a transaction that is rolled back. (c), the final memberships, is tools/testenv/membership_check.php.
projects = Project.where(identifier: ENV.fetch('J2R_PROJECTS').split(',')).to_a
ids = projects.map(&:id)
not_member = ->(column) { "NOT EXISTS (SELECT 1 FROM members m WHERE m.project_id = issues.project_id AND m.user_id = #{column})" }
temporary = Role.where(name: 'Migration (temporary)').count

watchers = Watcher.joins('JOIN issues ON issues.id = watchers.watchable_id').
  where(watchable_type: 'Issue', issues: { project_id: ids })
watchers_without = watchers.where(not_member.call('watchers.user_id')).count

issues = Issue.where(project_id: ids).where.not(assigned_to_id: nil).where(not_member.call('issues.assigned_to_id'))
saved = 0
refused = 0
tried = 0
issues.find_each do |issue|
  editor = issue.project.users.active.detect { |u| !u.admin? && u.allowed_to?(:edit_issues, issue.project) }
  other = Issue.where(project_id: issue.project_id).where.not(id: issue.id).
    where('assigned_to_id IS NULL OR assigned_to_id <> ?', issue.assigned_to_id).first
  next unless editor && other

  tried += 1
  ActiveRecord::Base.transaction do
    User.current = editor
    issue.init_journal(editor, 'proof: edited without touching the assignee')
    issue.safe_attributes = { 'subject' => "#{issue.subject} (proof)" }
    saved += 1 if issue.save
    other.init_journal(editor)
    other.assigned_to_id = issue.assigned_to_id
    refused += 1 unless other.save
    raise ActiveRecord::Rollback
  end
end
puts "temporary role left=#{temporary} watchers=#{watchers.count} not a member=#{watchers_without} " \
     "issues with an assignee not a member=#{issues.count} edited and saved=#{saved}/#{tried} same assignee refused elsewhere=#{refused}/#{tried}"
