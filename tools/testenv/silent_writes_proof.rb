# Proof (test instances only) that 13's tags on 7.0 and the relink leave no mail and no webhook (Jan, 2026-10-15):
#   J2R_PROOF=setup   J2R_PROOF_ISSUES=1,2 J2R_HOOK_URL=http://127.0.0.1:3999/hook  bundle exec rails runner silent_writes_proof.rb
#   J2R_PROOF=untag   J2R_PROOF_ISSUES=1,2   removes the issues' tags without a journal, so 13 can add them again
#   J2R_PROOF=journals J2R_PROOF_ISSUES=1,2 J2R_SINCE=<UTC time>   the journals written since, and by whom
#   J2R_PROOF=cleanup J2R_HOOK_URL=...       removes the webhook and the proof user, webhooks back as they were
# setup makes a user who wants every mail (active, Developer in the issues' projects, watcher of each issue) and a
# webhook for issue.updated in those projects, and switches webhooks on; mails go to files (configuration.yml).
# Redmine 5.1 has no webhooks: there only the mails are counted.
PROOF_LOGIN = 'j2r-mailproof'.freeze
ids = ENV.fetch('J2R_PROOF_ISSUES', '').split(',').map(&:to_i)
setting_file = Rails.root.join('tmp', 'j2r_webhooks_enabled_before')
webhooks = defined?(Webhook) ? true : false

case ENV.fetch('J2R_PROOF')
when 'setup'
  issues = Issue.where(id: ids).to_a
  admin = User.where(admin: true, status: User::STATUS_ACTIVE).order(:id).first
  projects = issues.map(&:project).uniq
  if webhooks
    File.write(setting_file, Setting.webhooks_enabled.to_s)
    Setting.webhooks_enabled = '1'
    Webhook.create!(url: ENV.fetch('J2R_HOOK_URL'), user: admin, active: true, events: %w[issue.created issue.updated],
                    projects: projects)
  end
  user = User.new(firstname: 'Mail', lastname: 'Proof', mail: 'j2r-mailproof@example.invalid', mail_notification: 'all')
  user.login = PROOF_LOGIN
  user.password = user.password_confirmation = SecureRandom.hex(16)
  user.status = User::STATUS_ACTIVE
  user.save!
  developer = Role.find_by!(name: 'Developer')
  projects.each { |project| Member.create!(user: user, project: project, role_ids: [developer.id]) }
  issues.each { |issue| Watcher.create!(watchable: issue, user: user) }
  puts "setup: user #{user.id}, webhook for #{projects.map(&:identifier).join(',')}, watching #{issues.size} issue(s), " \
       "notified events #{(Setting.notified_events & %w[issue_updated issue_note_added]).inspect}"
when 'untag'
  ids.each do |id|
    issue = Issue.find(id)
    issue.taggings.delete_all
    issue.update_column(:cached_tag_names, nil) if Issue.column_names.include?('cached_tag_names')
  end
  puts "untag: #{ids.size} issue(s)"
when 'journals'
  since = Time.zone.parse(ENV.fetch('J2R_SINCE'))
  scope = Journal.where(journalized_type: 'Issue', journalized_id: ids)
  scope.where('created_on >= ? OR updated_on >= ?', since, since).order(:id).each do |journal|
    details = journal.details.map { |d| "#{d.property}:#{d.prop_key}" }.join(',')
    puts "journal #{journal.id} issue ##{journal.journalized_id} by #{journal.user&.login} created #{journal.created_on.utc.iso8601} " \
         "updated_by #{journal.updated_by&.login || '-'} details [#{details}] notes #{journal.notes.to_s.size} chars"
  end
when 'cleanup'
  Webhook.where(url: ENV.fetch('J2R_HOOK_URL')).destroy_all if webhooks
  User.find_by(login: PROOF_LOGIN)&.destroy
  if File.exist?(setting_file)
    Setting.webhooks_enabled = File.read(setting_file)
    File.delete(setting_file)
  end
  puts "cleanup: webhooks #{webhooks ? Webhook.count : 'none (5.1)'}, proof user #{User.exists?(login: PROOF_LOGIN) ? 'left' : 'gone'}, " \
       "webhooks_enabled #{webhooks ? Setting.webhooks_enabled : '-'}"
end
