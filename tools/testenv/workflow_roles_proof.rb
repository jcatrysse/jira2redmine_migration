# Proof of Jan's decision 33 (test instances only): the "workflows" step of manual_steps.rb opens the transitions for
# the roles J2R_WORKFLOW_ROLES names, creates "Migration (temporary)" when it is listed, and stops on a role this
# Redmine lacks or one without Add or Edit issues. Each case runs in a transaction that is rolled back, and the
# step's list of opened transitions is put back as it was: nothing is kept.
#   bundle exec rails runner tools/testenv/workflow_roles_proof.rb
require 'stringio'

step = File.expand_path('manual_steps.rb', __dir__)
added = Rails.root.join('tmp', 'j2r_added_workflows.json')
kept = File.exist?(added) ? File.read(added) : nil
no_issues = -> { Role.create!(name: 'J2R proof without issues', permissions: %i[view_issues]) }
cases = [
  ['default (no J2R_WORKFLOW_ROLES)', nil, nil],
  ['Reporter', '["Reporter"]', nil],
  ['Migration (temporary)', '["Migration (temporary)"]', nil],
  ['a role this Redmine lacks', '["No such role"]', nil],
  ['a role without Add or Edit issues', '["J2R proof without issues"]', no_issues]
]
cases.each do |label, names, setup|
  ENV['J2R_STEP'] = 'workflows'
  names ? ENV['J2R_WORKFLOW_ROLES'] = names : ENV.delete('J2R_WORKFLOW_ROLES')
  result = nil
  ActiveRecord::Base.transaction do
    setup&.call
    before = WorkflowTransition.group(:role_id).count
    Object.send(:remove_const, :TEMPORARY_ROLE) if defined?(TEMPORARY_ROLE)
    output = StringIO.new
    $stdout = output
    begin
      load step
    rescue SystemExit => e
      result = "stopped: #{e.message}"
    ensure
      $stdout = STDOUT
    end
    opened = WorkflowTransition.group(:role_id).count.filter_map do |role_id, count|
      grown = count - before.fetch(role_id, 0)
      "#{Role.find(role_id).name} +#{grown}" if grown.positive?
    end
    result ||= "opened: #{opened.sort.join(', ')}"
    raise ActiveRecord::Rollback
  end
  kept ? File.write(added, kept) : FileUtils.rm_f(added)
  puts "#{label}: #{result}"
end
puts "after the proof: role 'Migration (temporary)' #{Role.exists?(name: 'Migration (temporary)') ? 'exists' : 'absent'}, " \
     "list file #{File.exist?(added) == !kept.nil? ? 'as before' : 'CHANGED'}"
