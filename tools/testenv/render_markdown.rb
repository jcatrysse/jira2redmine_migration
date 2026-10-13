# Render Markdown with Redmine's own CommonMark formatter, in bulk:
#   IN=in.json OUT=out.json bundle exec rails runner render_markdown.rb
# in.json: {"id": "markdown", ...}; out.json: {"id": "html", ...}. No project context, so Redmine links
# (#123, user#1, attachment:x) stay as text; callers resolve those themselves.
require 'json'
formatter = Redmine::WikiFormatting.formatter_for('common_mark')
input = JSON.parse(File.read(ENV.fetch('IN')))
output = input.transform_values { |text| text.nil? ? '' : formatter.new(text).to_html }
File.write(ENV.fetch('OUT'), JSON.generate(output))
puts "rendered #{output.size}"
