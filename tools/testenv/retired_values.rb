# B37: what an issue does with a select value the field no longer offers, per Redmine field format.
#   J2R_STEP=create|cleanup J2R_PROJECT=geos bundle exec rails runner retired_values.rb
# create:  two fields, "J2R retired list" (list) and "J2R retired enum" (enumeration, key/value), one issue
#          holding "Old" in both, then "Old" retired: removed from the list's possible values, made
#          inactive in the enumeration. Prints the ids for retired_values.mjs (display, edit form, save).
# cleanup: removes the issue and the two fields.
names = ['J2R retired list', 'J2R retired enum']
case ENV.fetch('J2R_STEP')
when 'create'
  project = Project.find_by!(identifier: ENV.fetch('J2R_PROJECT', 'geos'))
  tracker = project.trackers.first
  admin = User.find_by!(login: 'admin')
  User.current = admin
  common = { is_for_all: true, visible: true, editable: true, trackers: Tracker.all.to_a }
  list = IssueCustomField.create!(common.merge(name: names[0], field_format: 'list', possible_values: %w[Old New]))
  enum = IssueCustomField.create!(common.merge(name: names[1], field_format: 'enumeration'))
  old_value = enum.enumerations.create!(name: 'Old', position: 1)
  enum.enumerations.create!(name: 'New', position: 2)
  issue = Issue.create!(project: project, tracker: tracker, author: admin, subject: 'J2R retired values check',
                        custom_field_values: { list.id.to_s => 'Old', enum.id.to_s => old_value.id.to_s })
  list.update!(possible_values: %w[New])
  old_value.update!(active: false)
  puts({ issue: issue.id, project: project.identifier, tracker: tracker.id, list: list.id, enum: enum.id,
         old_enum_id: old_value.id }.to_json)
when 'cleanup'
  fields = IssueCustomField.where(name: names).to_a
  ids = fields.map(&:id)
  issues = Issue.joins(:custom_values).where(custom_values: { custom_field_id: ids }).distinct.to_a
  issues.each(&:destroy)
  fields.each(&:destroy)
  puts "removed issues=#{issues.size} fields=#{fields.size}"
end
