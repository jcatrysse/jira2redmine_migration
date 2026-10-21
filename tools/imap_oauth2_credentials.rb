#!/usr/bin/env ruby
# frozen_string_literal: true

# B92 (docs/PRODUCTION.md A.4 step 4b): the OAuth credentials of one mailbox, from 5.1 to 7.0.
#
# 5.1-stable-GEOxyz fetches mail with `rake redmine:email:receive_imap_oauth2 token_file=BASE`, which keeps two files:
# BASE.yml, the token (Ruby symbol keys, rewritten on every refresh), and BASE_client.yml, the application. 7.0-stable-
# GEOxyz has no such task: `rake redmine:email:receive_imap oauth2_credentials=FILE` reads one YAML file with string
# keys (lib/redmine/oauth2_client.rb): token_url, client_id, client_secret, refresh_token and scope, plus
# authorize_url and redirect_uri for `redmine:email:oauth2_authorize`. This writes that file.
#
#   ruby tools/imap_oauth2_credentials.rb /path/to/5.1/config/email_oauth2_NAME /etc/redmine/imap_oauth2/NAME.yml
#
# The first argument is the token_file value of the mailbox's 5.1 cron line, made absolute against the 5.1 root; as in
# 5.1, a name without the email_oauth2 prefix gets it (token_file=config/support reads config/email_oauth2_support.yml).
# Run it as the Redmine user, after 5.1's crontab is stopped: BASE.yml must be the token as 5.1 last wrote it. It
# prints no secret (the keys it wrote, the provider and the scopes), writes the new file with mode 0600 and never
# replaces an existing one.

require 'yaml'
require 'uri'

MICROSOFT_SCOPE = 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access'
GOOGLE_SCOPE = 'https://mail.google.com/'

def stop(message)
  warn "imap_oauth2_credentials: #{message}"
  exit 1
end

# 5.1 writes symbol keys (":refresh_token:") and symbol values (":mode: :header"); 7.0 reads string keys only.
def read_yaml(path)
  stop "#{path} not found" unless File.file?(path)
  data = YAML.safe_load_file(path, permitted_classes: [Symbol], aliases: true)
  stop "#{path} is not a YAML mapping" unless data.is_a?(Hash)
  data.to_h { |key, value| [key.to_s, value] }
rescue Psych::Exception => e
  stop "#{path} cannot be read as YAML (#{e.class})"
end

# 5.1's normalize_token_file.
def token_file_base(path)
  path = path.delete_suffix('.yml')
  name = File.basename(path)
  name.start_with?('email_oauth2') ? path : File.join(File.dirname(path), "email_oauth2_#{name}")
end

def present(value)
  value.is_a?(String) && !value.strip.empty? ? value.strip : nil
end

# 5.1 keeps the endpoints relative to `site` for Microsoft ("/TENANT/oauth2/v2.0/token"); 7.0 needs https URLs.
def absolute_url(value, site, name)
  uri = URI.parse(value)
  uri = URI.join(site.to_s, value) if uri.relative?
  stop "#{name} is not an https URL" unless uri.is_a?(URI::HTTPS)
  uri.to_s
rescue URI::Error
  stop "#{name} is not a URL"
end

stop 'usage: ruby tools/imap_oauth2_credentials.rb 5.1-TOKEN-FILE NEW-CREDENTIALS-FILE' unless ARGV.size == 2
base = token_file_base(ARGV[0])
target = ARGV[1]
stop "#{target} exists; it is never replaced" if File.exist?(target)
stop "the directory of #{target} does not exist" unless File.directory?(File.dirname(target))

token = read_yaml("#{base}.yml")
client = read_yaml("#{base}_client.yml")

refresh_token = present(token['refresh_token'])
stop "#{base}.yml holds no refresh_token: authorize the mailbox again (doc/IMAP_OAUTH2.md)" unless refresh_token
client_id = present(client['client_id'])
stop "#{base}_client.yml holds no client_id" unless client_id
client_secret = present(client['client_secret'])
unless client_secret
  stop "#{base}_client.yml holds no client_secret: a public client, which 5.1's guide allowed. 7.0 sends a client " \
       'secret with every token request: give the application one and authorize the mailbox again ' \
       '(doc/IMAP_OAUTH2.md)'
end
stop "#{base}_client.yml holds no token_url" unless present(client['token_url'])

token_url = absolute_url(client['token_url'], client['site'], 'token_url')
host = URI.parse(token_url).host
provider, scope = case host
                  when 'login.microsoftonline.com' then ['Microsoft 365', MICROSOFT_SCOPE]
                  when 'oauth2.googleapis.com', 'accounts.google.com' then ['Google', GOOGLE_SCOPE]
                  else [host, present(client['scope'])]
                  end
stop "#{base}_client.yml holds no scope for #{host}" unless scope

# What the mailbox owner consented to, as the token response named it; the refresh request asks for a subset only.
granted = present(token['scope'])&.split || []
needed = scope.split - %w[offline_access]
if granted.any? && !(needed - granted).empty?
  stop "the consent of #{base}.yml does not include #{(needed - granted).join(' ')}: authorize the mailbox again"
end

credentials = {
  'token_url' => token_url,
  'client_id' => client_id,
  'client_secret' => client_secret,
  'refresh_token' => refresh_token,
  'scope' => scope
}
if present(client['authorize_url'])
  credentials['authorize_url'] = absolute_url(client['authorize_url'], client['site'], 'authorize_url')
end
credentials['redirect_uri'] = present(client['redirect_uri']) if present(client['redirect_uri'])
credentials['authorize_params'] = client['auth_params'] if client['auth_params'].is_a?(Hash)

# Written beside its final name, then linked: a reader never sees half a file, and an existing file stays.
temporary = "#{target}.#{Process.pid}.tmp"
begin
  File.open(temporary, File::WRONLY | File::CREAT | File::EXCL, 0o600) { |file| file.write(credentials.to_yaml) }
  File.chmod(0o600, temporary)
  File.link(temporary, target)
rescue Errno::EEXIST
  stop "#{target} exists; it is never replaced"
ensure
  File.unlink(temporary) if File.exist?(temporary)
end

puts "#{target}: #{credentials.keys.join(', ')} (mode 600)"
puts "provider: #{provider}; scope: #{scope}"
puts "granted by the mailbox owner: #{granted.empty? ? '(not recorded in the token file)' : granted.join(' ')}"
