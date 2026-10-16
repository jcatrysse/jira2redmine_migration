# Jira Cloud to Redmine: test migrations and script upgrade

## Samenvatting voor Jan (2026-10-16, B2, 's nachts bijgewerkt)

**Stand.** Alles staat op `redmine70-migration`, QA groen (336 tests). Jira bleef read-only: alleen GET-calls.

**Vannacht**
- **Eén run met alle 14 projecten (beslissing 39), getest op 7.0** op een Redmine die al eigen data had: 4 u 28 min,
  ongeveer 4 u zonder de dubbele watcher-lezing die er nu uit is. Productie wordt dan ongeveer 6,5 tot 9 uur met de
  bijlagen. Alle projecten aangemaakt, DOC, LABO, ICT, LLE, SOIL, SSPT, ROV, GSPP en FPS voor het eerst. De
  relink-controle gaf 0: in één run is de relink alleen nog een controle. Details in `docs/PRODUCTION.md` B8.
- **Geen sporen (beslissing 37) bewezen op 5.1 en 7.0:** 0 journals van nabewerking, 0 issues met een nieuwe
  bijgewerkt-tijd, ook na een tags- en relink-ronde. Vereist Extended API 8e7096c of later (getest: ecc7dde).
- **Gevonden en opgelost, elk met een test:**
  - B76: de relink zag teksten zonder Jira-link als "te herschrijven".
  - B77: meerdere vaartuigen in één label hielden 4 SURV-issues tegen.
  - B79: een getal in een float-veld verloor zijn nullen (10 werd 1, 0 werd leeg); verify vergelijkt nu getallen.
  - B80: een te lang checklist-item liet de rest van de checklist vallen (alleen 5.1).
  - B81: een mislukte lees-actie in 11 stond als waarschuwing in de log.
  - B82: een oude lijst met geopende transities liet restore de workflow van een bestaande tracker wissen.
  - B83: een nieuwe run van 15 probeerde een mislukte checklist niet opnieuw.
- **Open voor jou: B78.** Het Jira-veld "Agent Sessions" (AI-agentsessies) hield SURV-1752 tegen. Advies: niet
  migreren, zoals beslissing 20; in de testconfig staat het zo.
- **Relatie DOC-448/449:** Jira heeft er twee, Redmine weigert de tweede als circulair; staat in B9 bij wat je na de
  run bekijkt.

**Gisteravond: jouw beslissingen 37 tot 39**
- **37, geen sporen:** E1 en E2 via de Extended API (vlag naast notify=false: geen journal bij 10's tweede pass,
  12, 13 en de relink, een aangepast commentaar houdt zijn tijden). Gebouwd en bewezen, zie boven.
- **38:** datums en % gereed van parents blijven afgeleid.
- **39, één run met alle 14 projecten:** `docs/PRODUCTION.md` herschreven (B1 tot B3, B5 tot B11, samenvatting,
  deel C): snapshots S1 na de structuur en reviews, S2 na 09 en 10, S3 na 11 tot 16; de relink alleen als controle;
  de vier batches als terugval (B11). Schijf: één pull van alle bijlagen, `paths.tmp` leeg direct na 09's push
  (116 GiB in `paths.tmp` en 116 GiB extra in `files/` op dat moment); met minder ruimte 09 in rondes.

**Vanmiddag**
- **Productie-runbook** `docs/PRODUCTION.md`: deel A (upgrade, van de coordinator), deel B (migratie, B2), deel C
  (prompt voor de lokale sessie). Beslissingen 29 tot 32 en 36 (één onderhoudsvenster) staan erin.
- **Bestaande Redmine (33 tot 35), gebouwd en bewezen op 5.1 en 7.0** met een Redmine die al een tracker "Task"
  met eigen workflow had: de globale workflow blijft identiek, de gemigreerde projecten krijgen hun eigen; de
  prioriteiten worden gemapt, er komt er geen bij; de rollen van de tijdelijke workflows komen uit de config.
  Onderweg **B73** (nieuwe prioriteit onderaan de rangorde) en **B74** (een gematchte tracker telde als nieuw, door
  het bewijs gevonden) gefixt.
- **Geen mail, geen webhooks:** 13 (tags op 7.0) en de relink gaan nu via de Extended API. Bewezen op 7.0: een
  gewone update geeft 1 mail en 2 webhooks, tags en relink 0 en 0. De journals van tags en herschreven
  beschrijvingen staan op naam van het migratie-account.

**Vanochtend: jouw beslissing 26 (conversie)**
- **Gemeten op alle teksten** (4.648 op 7.0 met WIKI, 1.444 op 5.1), wat naar Redmine gaat: voor 4.615
  zonder verlies (99,3%), na 4.634 (99,7%) met dezelfde meting; met de gecorrigeerde meting 4.647 van 4.648
  op 7.0 en 1.444 van 1.444 op 5.1. De oude meting (sleutel→#id telde als verlies): 4.604 (99,1%) en 1.435.
- **Echte fouten gefixt** (B60 tot B72, elk met een test): tabellen met samengevoegde cellen, vet/cursief
  dat Jira splitst (sterretjes zichtbaar), "<pre>" in codeblokken, een profiellink die de tekst ervoor
  opslokte (15 teksten, hele zinnen weg), getypte links zonder spatie (34), "#123" gevolgd door een letter
  (geen link in Redmine), kale adressen met backslashes (kapotte link), link in link, mention plak aan link.
- **MediaWiki:** geplakte broncode (WIKI-280, -282, -294, -295) wordt een codeblok met de exacte tekst uit
  Jira, i.p.v. Jira's kapotte tabel. Bewust geen Markdown-tabel: het is sjablooncode met `<pre>` in de
  cellen. Vet, [[links]] en {{sjablonen}} in een zin blijven zoals Jira ze toont.
- **Restverschillen** zijn verklaard: 20 sleutels→#id (zo beslist), 12 meetartefacten, 7 syntax die Jira
  zelf ook toont, 4 MediaWiki als codeblok, 1 link in link. Eén rest: WIKI-1160 (link midden in een woord).
- **Runs met de eindcode:** 5.1 en 7.0 volledig plus WIKI-batch, verify PASS. Onderweg **B71**: een 504
  van Atlassian stopte een leesfase; leesverzoeken worden nu opnieuw geprobeerd.
- **Visuele controle:** 43 vergelijkingspagina's klaar (buiten de repository).
- **Beslissing 27** (bijlagevenster 0) en **28** (geen extra veldregels, zoals in Jira) vastgelegd.

**Vannacht**
- **Fase 6 (M1), de hulpfuncties die per script verschilden.** Tijdstempels nu overal in UTC (PHP en de
  MySQL-sessie). Daarbij gevonden: **B58**, bijlagen stonden 1 à 2 uur te laat in Redmine (09 liet de
  offset van Jira vallen); gefixt, verify controleert het nu (149 van 149). Daarna **B59**: met de juiste
  tijden zette 11 bij statuswijzigingen de bijlagen die rond dat moment geüpload waren; gefixt, een
  statuswijziging toont alleen de bijlagen die ze zelf noemt. JSON-decodering, PDO-attributen en
  optieparsing bewust gelaten: elke variant past bij zijn script, een wijziging levert niets op.
- **Bewezen op 5.1 en 7.0**, verify PASS op beide. Op 5.1 met een opgenomen run tegenover die van
  gisteravond, alleen de verwachte verschillen: 576 uploads met de juiste aanmaaktijd, 13 notities minder
  (alleen bijlagen die door B58 fout matchten), 5 commentaren zonder die regels, 2 watchers minder (Redmine
  maakt de auteur van een notitie watcher). Op 7.0 dezelfde verschillen.
- **7.0 op de nieuwe heads** (core `ad17efd`, 9 plugins verschoven, o.a. helpdesk 4.4.0, met hun migraties):
  volledige run en cumulatieve WIKI-batch PASS, identiek aan de generale repetitie (alle stappen, aantallen,
  verify, workflows, restore).

**Generale repetitie van vanavond (al je beslissingen, plugins op hun huidige versie)**
- 7.0: 43 van de 44 plugins naar de `redmine70-migration`-heads; 5.1 met de productieset, ook
  redmine_custom_workflows op `0398974` (2.1.3 devel, wat productie draait; de testinstantie had 2.1.2).
  5.1 draaide op PHP 8.2.
- 5.1: verify PASS, tweede pass ongewijzigd, workflows toegepast (+1.940, tweede run +0 -0, 0 te reviewen),
  restore-bewijzen in orde (12/12), lidmaatschappen gelijk aan 04. 7.0 volledige run en de cumulatieve
  WIKI-batch: verify PASS, tweede pass ongewijzigd, workflows +0 -0, restore 101/101.
- Tegenover de runs van vanochtend: dezelfde aantallen en dezelfde verify-tabellen; de verschillen komen
  van de beslissingen van vandaag (meer geopende overgangen door B57, TEST niet aangemaakt, de 4
  G-groepen, de workflowstap nu meteen in de run).

**Jouw antwoorden van de avond, gebouwd (beslissingen 18 tot 25)**
- De unie blijft (18). FPS-conditie weggelaten, overgangen voorgesteld (19). Service desk-velden niet
  gemigreerd, IGNORED in plaats van MANUAL (20). Conversiekeuzes bevestigd (21). Productievolgorde van de
  batches in het runbook, TEST niet gemigreerd (22). PHP 8.2 of nieuwer met een tijdelijke MariaDB of MySQL
  (23): getest op 8.2, startcontrole met duidelijke melding, runbook-sectie "Prerequisites". Worklogs
  buiten scope (24). De vier RedmineUP-plugins niet naar 7.0 (25): stonden niet in de testsetup.
- Fase 6 (M1): de Extended API-hulpfuncties in één klasse en één regel voor `--phases`/`--skip` (een
  tikfout liet sommige scripts alle fasen draaien); bewezen met 7.116 identieke Redmine-requests.
- 5.1, volledige run met jouw beslissingen 12 en 13 (`a813ffb`, workflows met de latere fixes): verify
  PASS, een tweede pass van 08 tot 15 verandert niets, 0 watchers zonder toegang.
- 7.0: volledige run PASS met dezelfde code, de cumulatieve WIKI-batch ook PASS (B55 en B56 weg):
  1.882 van 1.883 issues, 885 relaties, 0 watchers zonder toegang. Beslissingen 12 en 13 bewezen zoals op
  5.1 (na restore 4.761 watchers behouden; 101 van 101 issues met een assignee zonder lidmaatschap
  bewerkbaar; workflows per project via de plugin met 115 scopes, tweede run +0 -0).
- Eerder vannacht, 7.0 WIKI-batch: 1.882 van 1.883 issues aangemaakt (1 wacht op GSPP); de 2 resterende
  verify-fouten bleken meetfouten van verify (B55, B56, gefixt). Relaties naar een latere batch komen
  vanzelf bij de rerun van 12 (bewezen: 30 van 30 naar WIKI).

**Jouw antwoorden van 10:20, gebouwd en bewezen (beslissingen 14 tot 17)**
- **Groepsovergangen (14).** Per project en groep in de config (`migration.workflows.group_roles`): G voor
  DOC Support, Survey Support (GEOS), ICT Support en de Crewing approvers (FPS); R voor GEOxyz-Jira-User
  (ICT, LLE, SIS, TEST) en Survey Support in TEST; ignore voor atlassian-addons-admin. 04 maakt de 4 rollen
  "<groep> (workflow)" (alleen Edit issues, niet assignable) en de 4 groepslidmaatschappen. Op 5.1 en 7.0
  gelijk: de reviewrijen gaan van 455 naar 12 (alleen de veldwaarde-conditie van FPS), 32 IGNORED; de
  runner +981, tweede run +0 -0; een DOC Support-gebruiker krijgt "New > In Progress" (Sub-task) en
  "New > Assigned" (New Document), een Developer buiten de groep niet; vergelijking met Jira 0 onverwacht,
  geen enkel antwoord meer in "groep geen lid"; lidmaatschappen gelijk aan 04 (79 + 4 = 83).
- **Bevestigd (15)**: Assignable user als Developer, Non member-groep pas na jouw READY, B37, B38/B39
  (instellingen in de runbook-stappen vóór de migratie en in `manual_steps.rb`), de extractie van de
  transitieschermen blijft.
- **B52 (16): batches plus relink.** Runbook: volgorde van de batches, 12 in elke batch, relink na de
  laatste, en de controle: "Texts that still hold a Jira link to a migrated issue" moet 0 zijn. Op 7.0:
  13 teksten herschreven, daarna 0.
- **Automation rules (17)**: jij exporteert ze één keer (runbook, sectie 0: waar in Jira); het bestand
  komt nooit in deze publieke repository (`.gitignore` als vangnet).
- **B57**: 05 miste 9 statussen van de FPS-workflow; nu gelezen uit Jira's status search, alleen statussen
  die een project gebruikt (16 ongebruikte niet).

**Jouw beslissingen van eerder vandaag, gebouwd en bewezen**
- **Tijdelijke migratierol (12).** De members-stap maakt elke gemigreerde gebruiker lid van elk
  gemigreerd project met de rol "Migration (temporary)", bovenop de lidmaatschappen van 04; `restore`
  haalt hem weg. B46/B50 (assignee-only en watcher-only leden) staan uit, in config. Op 5.1 na restore:
  (a) 1.142 watchers blijven (217 van gebruikers zonder lidmaatschap); (b) 12 issues met een assignee die
  geen lid meer is: 12 van 12 bewerkt en bewaard, dezelfde gebruiker elders opnieuw toewijzen geweigerd;
  (c) de lidmaatschappen zijn gelijk aan het voorstel van 04. Op 7.0 hetzelfde, met de WIKI-batch. Runbook: nooit
  `rake redmine:watchers:prune` na de migratie.
- **Workflows per project met jouw plugin (13).** Elk project krijgt exact zijn eigen Jira-workflow via
  redmine_project_workflows; de unie, door jou op te kuisen in de reviewtabel (IGNORED), is de globale
  workflow voor projecten zonder eigen. Op 5.1: 2.193 overgangen in 172 sets en 6 scripts "assign to the
  current user"; een tweede run verandert niets; GEOS Task biedt "Create RMA" niet meer, LABO Task wel;
  `restore` laat de toegepaste workflows staan. Permission-condities alleen voor de rollen met die
  permissie; transitieschermen geven geen regel (alle velden optioneel, zoals in Jira).
- **Plugin-upgrade geoefend.** De 5.1-instantie ging van de oude versie (v0.0.3, wat productie draait)
  naar de nieuwe (v0.1.6) bovenop de gemigreerde workflows: 4.945 antwoorden van de workflows identiek
  voor en na. Sessie C vond geen plugindefect buiten een testfix; zijn twee opmerkingen over mijn runner
  zijn gefixt, met test.

**Eerder vannacht gebouwd en bewezen**
- **Memberships (B31 a)**: 04 stelt voor uit de permission schemes; jij reviewt vóór `--confirm-push`.
- **Batches**: nieuwe selectiewaarden (B47, B51), velden aan voor nieuwe projecten (B53), wachtende
  parents (B38), links met oude keys (B54), opt-in relink na de laatste batch (B52).
- **Conversie**: GEOS, SIS en OAC 1.432 van 1.444 zonder verlies (99,2%); met WIKI 4.566 van 4.648
  (98,2%). Gefixt: linktekst, headings, status-lozenges, Outlook safelinks.
- **Non member users**: een recht voor alle gelicentieerde users wordt een voorstel voor die groep,
  MANUAL tot jij READY zet.

**Fouten die ook op `main` zitten (belangrijk voor productie)**: B31, B35, B41, B43, B44, B49 (details in
Findings). Advies: niet migreren vanuit `main`; de fixes staan op `redmine70-migration`, elk met een test.

**Nog open voor jou**
1. De visuele controle van de conversie (29 issues, bench stap 3): de pagina's staan klaar.
2. Vóór de productierun: de reviewtabel van 04 nakijken en READY zetten voor de Non member-groep (beslissing 15).
3. De productierun zelf volgens het runbook: batches in de beslisde volgorde, daarna relink en de controle 0.
4. ~~Het bijlagevenster bij commentaren (B59).~~ **Jij: 0** (beslissing 27), de standaard.

Start a Claude Code session on this repository, branch `redmine70-migration`, with:

> Read CLAUDE.md and docs/MIGRATION-PLAN.md, then carry on with the first open item of the work
> list on branch redmine70-migration. Report to me in Dutch at the end.

With several sessions in parallel, the session Jan starts is the coordinator; see "Sessions".

This file is the plan and the memory of that work. Update it as you go: results, findings, what
is left. Written 2026-10-13 from a reading of the code and Jan's answers in the planning session.

## Handover to B2 (session B, 2026-10-13, about 19:45 UTC)

Session B stops writing here; **B2 is the only writer of this file and of the repository** from now
on. Session B stays available to the coordinator for questions.

**State.** Branch `redmine70-migration`, everything pushed, worktree clean; the commit carrying this
section is the hand-over point. `tools/qa.sh`: 74 PHPUnit tests, PHPStan level 8 and PSR-12 clean at
the last code commit. Nothing is running. Nothing is half done in the code. The test instances in
session B's container (5.1 reset to its baseline dump with Extended API 132fff5; 7.0 holding the p8
run) are lost with that container: B2 rebuilds them.

**Done.** Phases 2 to 5; phase 6 first part (`src/` library: client and database factories, central
retry; proof: a full 5.1 run recorded through `tools/testenv/record_proxy.mjs` before and after the
refactor, **3,918 Redmine requests, 0 differing**, `compare_requests.php` exit 0); phase 7 item 12
and item 14 (status under item 14); phase 8 (runbook, README, `docs/SCRIPTS.md`, CHANGELOG).

**Gate on Extended API 96fe96a (5.1).** Script results equal to 132fff5 (365 issues created, 48
failed, 5 manual, 2 update failed, 6 waiting; journals 939; relations 60/60; watchers 777;
attachments 367); 373 created issues, 11 parents, author_id and created_on equal to Jira for all. No
SystemStackError. **The verify step did not finish** (token revoked during the run): rerun the gate
with verify. The extended_api branch is now 9867918 (docs only on top of 96fe96a), so the gate stays
valid for that commit. Production 5.1 = `main` = 132fff5 (force-pushed back on Jan's instruction).

**Last verify on 7.0 (run p8, all fixes except B24, B14 and Resolved landed later): 6 failures**, all
explained, each handled in `lib/verify.php` (commit "Verify: open parents in the counts, parents
outside the run, mentions and issue links count as links"), **not yet confirmed by a rerun**:

| check | cause | handled |
|---|---|---|
| counts tracker/status, OAC (2) | the two open parents (OAC-19, OAC-42) are Resolved by Jan's rule, not their Jira status | counts expect Resolved for `[open-parent]` rows |
| fields: parent, GEOS-87 | parent in a project outside the run (READY_FOR_REWRITE by design) | reported as "parent outside the run", not a failure |
| description links, GEOS-41, GEOS-116, SIS-49 | Redmine renders mentions, `#id` and e-mail autolinks as links Jira does not count, and the reverse | mentions, issue links and e-mail autolinks counted on both sides |

**Open, in order** (the first five need the new token):

1. New token: write probe (decision 1, expect 401 "scope does not match"), cloudId into
   `/tmp/claude-0/cid` (read by `tools/testenv/env.sh`). **DONE (B2)**, see work list item 6.
2. 03, 04, 16 with the granular scopes; B25 (16 calls `GET /rest/api/3/workflow`, gone from the spec):
   see the real answer, then fix with a test. **DONE (B2)**: B25 to B32 under Findings.
3. Full runs 5.1 and 7.0 with all fixes (`tools/testenv/run_all.sh 51|70 LABEL`); verify green on
   both, B14 and Resolved included; rerun the stability check (10 twice, second run changes nothing). **DONE (B2)**: verify green on both, see item 14; the stability check found B35.
4. Extended API gate on 5.1 with verify (item above). **DONE (B2): green**, `redmine70-migration` c806a17
   (code 161fa52) on 5.1, fresh full run: verify 22 checks, 0 failures, results equal to `main` 132fff5.
5. WIKI through the conversion bench pipeline (Text conversion protocol); then phase 7 item 13
   (conversion fixes with PHPUnit cases) and bench step 3 (visual check with Jan).
   **B2:** WIKI run and bench done (Text conversion protocol, results B2); run findings B37 to B43,
   conversion fixes B42 and B44 with PHPUnit cases. Then the pipeline measurement after the fixes and
   the remaining defect classes (safelinks, status lozenges, headings, issue link text): **DONE**, see
   "Results B2, night 2026-10-14". **Open:** Jan's look at the bench step 3 pages (made, see there).
6. SURV volume run: first implement the attachment sample test option (Jan's rule, under work list
   2; off by default; skipped files reported "skipped by sample" and expected-absent in verify).
   **B2: DONE**, option built (`attachments.test_sample`); SURV run on 5.1, see Results.
7. Phase 6 rest: the 8 helper functions still copied in every script (M1), CLI and config helpers,
   each step proven with identical recorded requests. **Open** (B2 did not start it; not Jira-bound).
8. Phase 9: final runs on both targets, numbers in "Results".

**Test environment, for `tools/testenv/setup.sh` in a fresh container.**
- Attach the private plugin repositories first (`add_repo`); 44 plugins for 5.1 in
  `plugins-5.1.tsv`. Takes about 15 minutes per target; disk is tight: setup clones with
  `--depth 1`; remove `.git` directories under vendor if bundler leaves full clones (4.9 GB once).
- Already handled by setup.sh: `activerecord-session_store` in Gemfile.local (5.1),
  redmine_custom_workflows v2.1.2 on 5.1, redmineup pinned to 1.1.4 (1.1.5+ duplicates a route),
  issue_recurring moved aside during `db:migrate` on an empty database, plugin directory = plugin id,
  `generate_secret_token`.
- `config/config.local.php` is gitignored and must be recreated. Session B's version reads everything
  from the environment: `jira.base_url = 'https://'.getenv('JIRA_SITE')`, username/api_token from
  `JIRA_EMAIL`/`JIRA_API_TOKEN`, `cloud_id` from `JIRA_CLOUD_ID`; `redmine.base_url`/`api_key` from
  `REDMINE_URL`/`REDMINE_API_KEY`; `extended_api` enabled, prefix `/extended_api`; `paths.tmp` from
  `J2R_TMP`; `migration.issues.jql` from `J2R_JQL` (default `project in (GEOS, SIS, OAC)`);
  `default_redmine_author_id` 1 for issues and journals; staging DSN from `STAGING_DSN`, user
  `jira2redmine`. `tools/testenv/env.sh 51|70` sets those variables (API keys from `setup5x.out`).
- Redmine runs on puma (`start.sh 51|70`, ports 3051/3070); `reset.sh` restores the baseline dump.
  Do not use `pkill -f` with a pattern that matches your own shell (it killed the shell, exit 144).
- run_all.sh: `RECORD=file.jsonl` records every Redmine request through the proxy (port 31xx);
  `manual_steps.rb` does workflows/members/restore. Apache + Passenger + mod_xsendfile for the large
  attachment test: `tools/testenv/apache-redmine70.conf`, `large_attachment_apache.sh`.
- Verify reports and run logs hold Jira data: keep them outside the repository.

## Goal

- Run **test migrations** from Jira Cloud to two full Redmine instances, both on PostgreSQL 16:
  - **Redmine 5.1** with the GEOxyz plugins as they run in production today;
  - **Redmine 7.0** (`7.0-stable-GEOxyz`) with the plugins' `redmine70-migration` branches.
- Use those runs to find and fix bugs, raise code quality and documentation, settle the ideal
  workflow, and automate manual steps where that is worth it.
- **Out of scope:** the production migration itself. Jan does that later, by hand or partly with
  AI, using what this work produces.
- Test data may be used freely for the test migrations; staging and target data are deleted after.

## Status

| | |
|---|---|
| Branch | `redmine70-migration` (from `main` at `1de1fc9`) |
| Phase | 2 to 5, 8 and 9 done (phase 9 green on 5.1 and 7.0 with Jan's decisions 12 and 13, B2, 2026-10-14); 6 first part and 7 largely done; bench step 3 pages made (Jan to look); open: decisions for Jan (summary at the top), phase 6 rest |
| Scripts | 16 scripts, ~39,000 lines of PHP, no shared library, no tests |
| Targets | Redmine 5.1 and 7.0; the scripts stay dual-target (unlike the plugins, 5.1 is not dropped) |
| Extended API | to be made compatible with Redmine 5.1, 6.1 and 7.0 (own repo, own branch) |

## Decided by Jan (2026-10-13)

1. **Jira access is read-only, enforced by Jira.** A scoped API token with read scopes only
   (`read:jira-work`, `read:jira-user`; a granular `read:*` scope is added only when a script
   proves it needs one; never a `write:*`, `delete:*` or `manage:*` scope). Scoped tokens only
   work through the gateway `https://api.atlassian.com/ex/jira/{cloudId}`. Before the first run,
   prove it is read-only with a write probe on an issue that does not exist: the answer must be a
   scope refusal (401), not a 404.
2. **Fix versions and components are not used at GEOxyz.** They stay out of scope: extracted into
   staging as today, not migrated.
3. **Large attachments: no code change.** The SharePoint offload stays in the code, switched off
   with `attachments.sharepoint.offload_threshold_bytes = null` (the default). Test for real how
   Redmine copes with very large attachments (videos of 1 to 2 GB), with a few files only, not
   every attachment.
4. **Mentions become `@login`.** That is Redmine core's mention syntax since 5.0: rendered as a
   mention, notifies the user on creation, and found by Jan's mention search. `redmine_zenedit`
   uses the same `@login` syntax and switches its own mention code off on Redmine 5.0+
   (`unless defined?(Redmine::Acts::Mentionable)`), so the choice is the same with or without
   zenedit. Today the scripts write `user#<id>` (a plain user link) from profile links, and the
   ADF path turns a mention into inline code text.
5. **Retry on rate limits** everywhere, central, for Jira and for Redmine.
6. **Extended API compatible with Redmine 5.1, 6.1 and 7.0.** This revises the 2026-10-07
   decision ("Redmine 7 only") for `redmine_extended_api` only. The webhook patch is applied only
   when `::Webhook` exists.
7. **Text conversion** (Jira to Redmine Markdown) must be tested thoroughly; it has caused trouble
   before.
8. **Manual steps stay scripted** (2026-10-13, after the runbook): `manual_steps.rb`
   workflows/members/restore; no Extended API import mode.
9. **Attachment prune off during the run** is a runbook step; no code change (2026-10-13).
10. **Project memberships (B31): option (a), a proposal Jan reviews** (2026-10-13, relayed by the
    coordinator). 04 proposes per project from the permission scheme: a group with Browse projects
    becomes a group member with Developer, a group with Administer projects Manager; users in the
    Administrators project role direct members with Manager; groups in project roles group members;
    atlassian-addons-project-access IGNORED; no expansion from a user to the user's groups. Jan
    reviews and edits `migration_mapping_project_role_groups` before `--confirm-push`; the
    automation hash keeps his edits; only READY rows are pushed. The `members` step only activates
    locked users. Done (B2, 2026-10-14): commits `f3443eb`, `17b8ec5`; review query in the runbook.
11. **Watchers without project access are migrated (B50, option b)** (2026-10-14, relayed by the
    coordinator), so the history shows who followed an issue. Built like B46: 04 proposes each watcher
    no membership covers as a direct member, pseudo role -5 "Watcher of issues (no membership)",
    READY, note "Watcher only, not in the project's permission groups", role from
    `migration.roles.watcher_only_role`; 14 then adds the watcher, verify expects it present. 14's jira
    phase runs before 04. The role is still open (asked of Jan; Developer until then). Commit `24d3611`.
    **Replaced the same day by decision 12**: the watcher-only rows stay in the code, off by default.
12. **A temporary migration role instead of assignee-only and watcher-only members** (2026-10-14,
    relayed by the coordinator; replaces 11 and B46's rows). During the import every migrated user is
    a member of every migrated project with one temporary role ("Migration (temporary)", every
    permission, assignable), on top of the final memberships from 04 (decision 10). After the run,
    `restore` removes only that role (and members left without a role) and locks the activated users
    again: the final roles reflect Jira today, assignees and watchers stay as they were. Redmine keeps
    them: removing a member role prunes no watchers (only `rake redmine:watchers:prune`, or deleting a
    user or group, does), and an assignee is only validated on a new issue or when it changes. B46's
    and B50's rows are off by default; 14 migrates every watcher, verify expects them all. Runbook: never
    run `rake redmine:watchers:prune` after the migration; a watcher without access gets no
    notifications. To prove on both targets after `restore`: a watcher without access is still there, an
    issue whose assignee lost the membership can be edited and saved, the final memberships equal 04's
    proposal. **Built** (`52b6fd4`; `RESTORE=1` in `run_all.sh` runs restore and the proofs
    `temporary_role_proof.rb`, `membership_check.php`); proof runs see Results.
13. **Workflows** (2026-10-14, relayed by the coordinator):
    - applied by a runner after the migration: it writes the rows Jan approved in
      `migration_mapping_workflow_transitions` into Redmine and replaces the temporary open workflows;
      repeatable, preview first, then `--confirm-push`; 5.1 and 7.0;
    - a tracker fed by several Jira workflows gets their union (11 trackers, 2 to 14 workflows each);
    - transition screens: fields the screen makes required become Redmine field permissions (per
      tracker, role and target status), as review rows applied by the same runner. On this Jira that is
      0 rows: the only screen in use ("Resolve Issue Screen": Solution, Resolution, Log Work) has no
      required field in any of the 12 field configurations. Open for Jan: a rule Jira does not have,
      such as Solution required on Closed; the extraction is not built until he answers;
    - permission conditions: per role, only the Redmine roles that hold the Jira permission in the
      project (its permission scheme's holders, mapped by 04) get the transition;
    - "assign to the current user" (12 rows): a small redmine_custom_workflows script per tracker and
      status (v2.1.2 on 5.1, the 7.0 branch on 7.0), generated from the proposal and reviewable;
    - transitions limited to groups that are not members of the project: review rows with the group,
      Jan decides per case;
    - the 9 Jira statuses without a Redmine status (unused): skipped.
    **Jan's answers to the examples (2026-10-14):** (1) the union, harmonised in the review table: he
    marks the transitions he does not want anywhere (such as "Create RMA" on Task) IGNORED; the report
    lists per tracker each union transition with its Jira workflows, projects and the issues in its
    from-status; (2) transition screens: no new rule, as in Jira (0 rows; the only screen in use is
    "Resolve Issue Screen", all its fields optional in all 12 field configurations), and no extraction
    to build. It was built before this answer arrived (16 exports the field configuration items, 0 rows
    result); kept because it is what lets those 96 transition rows go without review. Open for Jan:
    keep it or remove it; (3) rule 4 confirmed.
    **Rule 1 replaced the same day (Jan, 2026-10-14): each project gets exactly its own Jira workflow**
    through his plugin redmine_project_workflows; the union, cleaned by him in the review table, is the
    global workflow for projects without their own (B2's choice, the coordinator's suggestion).
    `migration.workflows.mode`: `project` (default) or `union` (Redmine without the plugin). The review
    table holds both (`redmine_project_id`, 0 = union). On the plugin's 5.1 version a project role
    without transitions follows the global workflow (it cannot store an empty one); on 7.0 it is an own,
    empty workflow. Built in `a4a3d9c`; plugin points reported to the coordinator, the plugin repository
    not changed.
    Context from Jan (2026-10-14): production 5.1 runs an old, somewhat buggy version of the plugin that
    works; the renewed version (v0.1.x, branch `redmine70-migration`) has not run in production yet. The
    migration uses the new version and is its test. Session C (jcatrysse/redmine_project_workflows)
    verifies the reported points with failing tests, fixes the new version and checks the upgrade from
    the old data model; it may ask for a migration run through its fixed version. Plugin version per run:
    5.1 test instance v0.0.3 (`d61b1de`, its default branch), 7.0 v0.1.6 (`f2c1c2f`, `redmine70-migration`);
    5.1 moves to the new version once session C confirms it supports 5.1. The runner writes the new
    version's model (scopes) whenever the plugin has the scope table.
    Session C confirmed (v0.1.6 supports 5.1, 6.1 and 7.0): **the 5.1 test instance moved to v0.1.6 on
    2026-10-14 as an upgrade on top of the migrated state** (rehearsal of production, Results). Runs
    before that on 5.1 used v0.0.3 (final7 and earlier), from then on v0.1.6; 7.0 used v0.1.6 throughout.
    **Built** (`20cc6e8`, `7f1d898`, `8ff9e11`): rule 3 after all, from the field configurations 16 now
    exports (0 rows on this Jira); `tools/workflow_plan.php` and `tools/apply_workflows.rb` (preview,
    `--confirm-push`, repeatable); `WORKFLOWS=1` in `run_all.sh`. Runbook section 5. Information for Jan:
    his plugin redmine_project_workflows (alpha), installed on both test instances, could give each
    project its own Jira workflow instead of the union.

14. **Transitions limited to a group that is not a member** (2026-10-14, about 10:20 UTC, relayed by the
    coordinator): B2's advice per group (the options table in Results), in the config
    `migration.workflows.group_roles`, one setting per project and group: **G** for DOC / DOC Support,
    GEOS / Survey Support, ICT / ICT Support, FPS / GEOxyz- Crewing approvers (own role `%s (workflow)`,
    edit_issues only, no view_issues, not assignable, created through the Extended API when missing, a 04
    membership row); **R** for GEOxyz-Jira-User in ICT, LLE, SIS and TEST, and TEST / Survey Support;
    **ignore** for atlassian-addons-admin in GEOS, SOIL and SURV. A group not listed stays a review row.
    **Built** (`6689dd6` refactor, `877b363`), proofs in Results.
15. **Confirmed** (2026-10-14): Assignable user as Developer; the Non member users group only after his
    READY; B37 (unoffered values added, then inactive); B38/B39 cross-project subtasks and relations on
    (runbook section 0, `manual_steps.rb`); the transition screen extraction stays.
16. **B52: batches plus relink, not one run** (2026-10-14; for production replaced by decision 39, one run, the
    batches kept as its fallback). The production procedure is in the runbook
    (section 1, Batches): batch order, 12 in every batch, `tools/relink_issue_links.php` after the last
    batch, and its check: the texts that still hold a Jira link to a migrated issue must be 0 (`06fd323`).
17. **Automation rules**: Jan exports them himself once as JSON, kept outside this public repository
    (runbook section 0: where in Jira, never commit; `.gitignore` stops `automation-rules*.json`).

Jan's answers to the six open points of the summary (2026-10-14, evening, relayed by the coordinator):

18. **The union per tracker stays as it is**: no cleanup; it remains the global workflow for projects without
    their own, "Create RMA" on Task included. The report of `tools/workflow_proposal.php` stays available if he
    wants to clean it later in the GUI.
19. **FPS, the field value condition ("Screen", one option of a select field): proposed without the
    condition.** The transitions stay limited to the Crewing approvers' own role (decision 14, G); no
    custom_workflows script. `migration.workflows.dropped_conditions` (`911e26c`).
20. **Service desk fields "Organizations" and "Request participants": not migrated**, as the configured
    outcome: 08 marks them IGNORED with a note instead of MANUAL (`migration.custom_fields.not_migrated`,
    `911e26c`); their values stay in Jira only (runbook section 2).
21. **Conversion choices confirmed as proposed**: a status lozenge becomes inline code; a link to a migrated
    issue with its own text reads "text (#id)"; a link to an issue outside the migration keeps its Jira link
    with the key as text; Outlook safe links become the plain link.
22. **Production batch order: WIKI; then GEOS, SIS, OAC; then SURV, DOC, LABO; then ICT, LLE, SOIL, SSPT, ROV,
    GSPP, FPS. TEST is not migrated** (the order now matters only for the fallback of decision 39; TEST stays out) (`migration.projects.not_migrated`: 01 marks it IGNORED and does not
    create it, `911e26c`). Runbook section 1, Batches.
23. **Production environment: an existing server with PHP 8.2 or newer, plus a temporary MySQL/MariaDB for
    staging** (no port of the staging SQL to PostgreSQL). Verified on PHP 8.2.34 (QA green; nothing 8.3-only);
    a start-up check stops with a clear message below 8.2.0 or without the required extensions (`0827b1f`);
    runbook section "Prerequisites".
24. **Jira worklogs are not migrated** (out of scope); M12's worklog half is closed.
25. **The RedmineUP plugins redmine_drive, redmine_questions, redmine_reporter and redmine_resources do not move to
    Redmine 7** (2026-10-14, relayed by the coordinator): with them every issue mail fails on 7.0 (redmine_drive's
    alias_method on details_to_strings clashes with redmine_checklists' prepend). The test setup never installed
    them (`tools/testenv/plugins-5.1.tsv` does not list them, neither instance has them), so nothing to drop;
    `redmine_reporter_dashboards` is a different plugin and stays.
26. **The remaining text conversion losses: fix the real defects and convert WIKI's MediaWiki text** (2026-10-15
    morning, relayed by the coordinator). (1) The real defects in GEOS, SIS, OAC and WIKI: `**` visible inside raw
    HTML blocks, a word glued to an attachment reference, the lost table row, literal link markup shown as text,
    literal MediaWiki HTML tags shown as text, the remaining "link's own text" cases, and any other real loss found
    on the way. (2) WIKI's MediaWiki content that Jira itself shows as raw text (table syntax, `<nowiki>`, `<pre>`
    and the like; WIKI-280, -282, -294, -295, -843 and others) becomes proper Markdown (tables, code blocks, plain
    text), conservatively: only what converts without guessing; anything ambiguous stays a code block, and the plan
    says which. (3) The measure no longer counts the Jira key to `#id` rewrite as a loss (by design); report the
    old and the corrected number and the remaining losses by class after fresh 5.1 and 7.0 runs, WIKI batch
    included. (4) New side-by-side pages of the curated set for Jan's visual check, outside the repository. Each
    class gets an anonymised PHPUnit case that fails without the fix; refactor and behaviour commits apart.
27. **Comment attachment window (B59): 0** (2026-10-15, relayed by the coordinator): a comment lists only the
    attachments it names itself; `migration.journals.comment_attachment_window` stays 0, the default.
28. **No extra field rule for a role without transitions: as in Jira** (Jan, 2026-10-15). Asked whether the
    status and the other fields can be read-only or hidden where a role has no transition from a status.
    Redmine 5.1 and 7.0 (same code): without a transition for the user's roles the form shows the status as
    text and drops a status change sent anyway; an admin gets every role's transitions, so an admin always
    sees a list (checked on 5.1.13 in a rolled-back transaction: GEOS #15, 8 transitions taken away for the
    user's roles, 0 statuses offered, the admin still offered 2). Other fields can be read-only per tracker,
    role and status in core (Workflow > Fields permissions, enforced on the server); hiding is per role only
    (custom field visibility; redmine_issue_field_visibility, in the plugin set, hides 8 core fields per
    role), not per status. Jan: keep it as in Jira, where Edit issues allows editing apart from
    transitions; no field permissions are generated for it.

Jan's decisions of 2026-10-15 (afternoon, relayed by the coordinator):

29. **Production order: first the upgrade, then the migration into 7.0.** GEOxyz's Redmine 5.1 is upgraded to 7.0
    and checked; Jira is then migrated directly into that 7.0. Redmine 5.1 stays a supported target of the
    scripts, but production goes to 7.0.
30. **A full test migration (rehearsal) on a clone of Jan's production VM**, with outgoing mail off, run by a
    local Claude Code session on that VM: first the upgrade, then the migration on the real data. Every project
    of the four batches of decision 22 (WIKI; GEOS, SIS, OAC; SURV, DOC, LABO; ICT, LLE, SOIL, SSPT, ROV, GSPP,
    FPS), with relink, workflows and restore, attachments with `attachments.test_sample`. Why: every test so far
    targeted a fresh, empty Redmine, so matching against existing users, projects, trackers, statuses and custom
    fields was never exercised, and nine projects (DOC, LABO, ICT, LLE, SOIL, SSPT, ROV, GSPP, FPS) never ran.
31. **One production runbook**, `docs/PRODUCTION.md`: English like the runbook, a short Dutch summary at the top,
    no hostnames or secrets. B2 writes and owns the migration part (part B) and the prompt for the local session
    (part C); the coordinator writes the upgrade part (5.1-stable-GEOxyz to 7.0-stable-GEOxyz with the 44
    plugins, from the upgrade rehearsal), included as written (part A).
32. **Main after the rehearsal.** The rehearsal runs from `redmine70-migration`. Afterwards the branches go to
    main once, rewritten as Jan without AI traces, and the coordinator checks per repository that the tree on
    main is identical to what was tested. Production runs from main.

Jan's answers to B2's three questions about an existing Redmine (2026-10-15, relayed by the coordinator):

33. **The roles the `workflows` step opens are configurable** (`migration.workflows.open_workflow_roles`, default
    Manager and Developer, so the test instances behave as before; `manual_steps.rb` reads them as
    `J2R_WORKFLOW_ROLES`). The rehearsal lists the roles of GEOxyz's Redmine that create issues (docs/PRODUCTION.md,
    B5) and Jan confirms the list before the first batch. Built (`93af5ca`): a role the Redmine lacks, or one without
    Add or Edit issues, stops the step; "Migration (temporary)" can be listed (the step creates it), and then the
    open workflow reaches only the migrated users. The import runs as an admin, who gets every role's
    transitions (`Issue#roles_for_workflow`, 5.1 and 7.0), so one usable role is enough.
34. **Workflows in an existing Redmine: only per project.** The runner writes each migrated project's own Jira
    workflow (redmine_project_workflows) and leaves the global workflow of existing trackers untouched (no union
    written as global, no global transitions removed), so existing projects without their own workflow see no
    change. Decision 18 (the union is the global workflow) still holds for the trackers 07 creates; a tracker
    matched to an existing one keeps its global workflow. Mode `union` (Redmine without the plugin) is unchanged.
    Built (`6623bd4`).
35. **Priorities map onto existing Redmine priorities, no new ones.** 06 proposes the priority
    `migration.priorities.mappings` names, else the one of the same name; anything else is a review row (MANUAL)
    Jan maps, and 10 holds back the issues that use it until then. 06 creates a priority only with
    `migration.priorities.create_missing`. Reason: one priority scheme for every project, as redmine_itil_priority
    computes the priority from impact and urgency. B73 fixed anyway: a created priority goes after the existing
    ones (`2a7c230`, `9c85cc6`). The test instances map Blocker => Immediate, Showstopper => Urgent, Medium =>
    Normal in `config/config.local.php`.

Jan's decisions of 2026-10-15, later in the afternoon (relayed by the coordinator):

- **13 and the relink write through the Extended API with notify=false** like the other steps, so no mail and no
  webhook leaves from them (`2df782a`; docs/PRODUCTION.md B5: stealth mode stays as a safety net, inactive webhooks
  are needed only when redmine_checklists' "issue done ratio" stays on).
36. **Production part B in one maintenance window.** From its start Jira is read-only for the users (Jan sets it by
    hand as a Jira admin; the scripts and the token never write to Jira) and Redmine is closed to them; after the last
    batch, the relink, the workflows, restore and the checks, Redmine opens and everyone works in Redmine. Reason: the
    scripts skip an issue that is already migrated, so a Jira change after a batch's extraction would be lost, and a
    rollback to a snapshot would erase what users did in Redmine meanwhile. Part A stays its own window before it
    (decision 29). docs/PRODUCTION.md B5 says how Redmine is closed (the web server answers a maintenance page to
    everyone; the migration uses a local-only entry) and that the crontab is off for the window; inside the window a
    rollback costs no user work, after it only a fix forward (B10). The temporarily activated accounts and the open
    workflows reach nobody while Redmine is closed. The rehearsal measures the window's length.

Jan's answers to the traces of post-processing (2026-10-15, evening, relayed by the coordinator; the options are in
Results, "Traces of post-processing"):

37. **No trace, through the Extended API: options E1 and E2.** An admin-only request flag next to `notify=false` on
    `/extended_api`: under it no `Issue#create_journal`, no `Issue#create_parent_issue_journal` ("Subtask added") and
    no `IssueRelation` journals (E1), and a journal update through `/extended_api` with the flag keeps the journal's
    `updated_on` and `updated_by_id` (E2). Not R2. redmine_extended_api builds it in its own session (branch
    `redmine70-migration`, Redmine 5.1, 6.1 and 7.0); the coordinator passes on the parameter's final name and the
    commit. Then B2 sends the flag from 10's second pass, 12, 13 and the relink, with a test each, and proves it with
    a run on 5.1 and 7.0 on a non-empty Redmine: 0 journals from post-processing, the updated times unchanged apart
    from the derived parent fields (38). Until then nothing changes in the scripts for this.
    **Built and proven** (2026-10-15/16; Results, "Decision 37, the proof": 0 journals, 0 updated times on 5.1 and 7.0): the plugin's parameter is `skip_journal` (redmine_extended_api `506603c`, docs
    `8e7096c`); the scripts send it with notify=false from 10's second pass (and its open-parent retry), 12, 13 and the
    relink (`ExtendedApi::NO_TRACE`, `135fa96`); 10's create sets no parent, so it needs none; 11's comments carry notes
    and go without it (the plugin refuses notes with the flag). **The scripts need redmine_extended_api `8e7096c` or
    later** (final and tested in the 44-plugin set on 7.0: `ecc7dde`, a test guard after `8e7096c`; Jan agreed with its
    choices: the name, 403 for a non-admin, 422 for notes with the flag, the updated time kept on every saved issue), and production's Extended API must be that version: it goes to main with the rest after the rehearsal
    (decision 32); docs/PRODUCTION.md B2 and B5 check it. Proofs in Results.
38. **Parents' dates and done ratio stay derived** (option S not taken). The few parents with a new updated time and
    no journal (3 per run of GEOS, SIS and OAC) are accepted.
39. **One run of all 14 projects instead of four batches** (B2's advice). Three VM snapshots at the natural pauses:
    after the structure and its reviews, after 09 and 10, after 11 to 16; the relink stays only as the check that 0
    links are left; the four batches stay in the runbook as the fallback. This replaces decisions 16 and 22 for
    production: the order of 22 matters only for the fallback. Disk (B2's choice, docs/PRODUCTION.md B2): one pull of
    every attachment, `paths.tmp` emptied right after 09's push; the server needs room for about 116 GiB twice at
    that moment (`paths.tmp` and Redmine's `files/`); with less, 09 runs in rounds (`--download-limit`), each round
    pushed and emptied before the next. The rehearsal (decision 30) runs it this way.

## Decided overnight (2026-10-13/14), for Jan to confirm

Taken by B2 on the coordinator's instruction (recommended option, no waiting); each can be reversed.
Per item: what was done, the alternatives, why this one. **Jan's answers (2026-10-14, decisions 15 to 17):**
Assignable user, the Non member users group after READY, B37 and B38/B39 confirmed; B52 replaced by
batches plus relink (16); automation rules exported by Jan (17). The rest (service desk fields, conversion
choices) is still open.

- **Assignable user is proposed too** (B31 extension, coordinator agreed): a group with Assignable
  user in the permission scheme is proposed as group member with Developer, because Redmine assigns
  issues only to members. In the test projects it adds 2 groups that Browse projects does not give.
  Alternative: Browse projects only (those groups' issues then need B46's assignee rows).
- **A grant to an application role ("every licensed user", GSPP and ROV) is proposed for Redmine's
  built-in group "Non member users"** with the mapped role, as MANUAL_INTERVENTION_REQUIRED with a
  note: it gives the role to every logged-in user who is not a member, so it is pushed only once Jan
  sets READY. It does not make anyone assignable (Redmine assigns to User and Group members only).
  REST and Extended API create such a membership on 7.0 (201). Alternatives: (b) READY at once,
  (c) only a note (the earlier behaviour), (d) every licensed user as a direct member (expansion).
- **B46, option (a) with the coordinator's refinement: an issue's assignee that no membership covers
  is proposed as a direct member** in the same review table, role `migration.roles.assignee_only_role`
  (default Developer; alternative: a dedicated assignable role with minimal permissions, so a moved or
  departed person keeps the assignment without access Jira did not give), note "Assignee only, not in
  the project's permission groups", status READY (without these rows Redmine refuses the issues; Jan
  reviews them before the push like every proposal). 04 now runs after the issues are staged; the
  members step (activate locked users) runs before 04's push. Without it Redmine refused 10 issues in GEOS, SIS and OAC and 47
  in WIKI ("Assignee is invalid"): Jira keeps an assignee who lost the permission. Alternatives:
  (b) 10 creates such an issue unassigned and keeps the Jira assignee in the mapping notes and the run
  report (the assignee is lost in Redmine); (c) the old members step, limited to assignees. (a) keeps
  the assignee and follows Jan's review model.
- **B50: superseded by Jan's decision 11 (option b, migrate them).** Overnight choice was option (a),
  not migrated, as in Jira. Redmine
  drops a watcher who is not a member (204, not stored); with Jira's membership model that user has no
  Browse projects in Jira either and cannot see the issue there (38 in the GEOS/SIS/OAC run, 372 in the
  WIKI batch). 14 notes `[no-access]` and keeps the row FAILED, so a rerun adds the watcher once a
  membership is given; verify expects them absent. Alternative: (b) watcher-only members, proposed like
  B46's assignees (gives access Jira does not give).
- **B52, coordinator's decision: an opt-in relink after the last batch** (`tools/relink_issue_links.php`),
  only for texts whose links now resolve and whose hash shows no change in Redmine since the push; the
  runbook recommends one run with all projects for production, which avoids the case. Alternatives:
  leave the Jira links (the key stays readable), or rewrite without the guard (would overwrite edits).
- **Automation rules: no export through the automation API.** 16 logs why it skips them
  (`AutomationRules::SKIPPED`). Recommendation: export the rules by hand from Jira (Export rules,
  JSON), keep the file outside the repository and use it as the checklist for what to rebuild in
  Redmine (runbook, section 0). Alternative: an export through Atlassian's automation API (own
  token and scope, not the platform API), to rebuild them as custom workflows.
- **B37, option (a): a select value Jira no longer offers is added to the Redmine field.** After the
  run it is retired per Redmine format: an enumeration (key/value, 08's format for every select) value
  is made inactive, a plain list value is removed from the possible values. Checked on 5.1 and 7.0
  (`tools/testenv/retired_values.rb/.mjs`, browser and REST), the same on both targets and for both
  formats: the issues that keep the value show it, the edit form still offers it on those issues
  (selected), a save keeps it (browser saved, REST PUT 204), and no other issue can get it (REST 422
  "is not included in the list"). 08's transform lists every such value with its issue count
  (`[unoffered]` lines, run summary). Runbook section 2. Alternatives: (b) map the value to an
  existing one by configuration, (c) leave the field empty on those issues.
- **B38, option (a): "Allow cross-project subtasks: with all projects" (and B39: cross-project issue
  relations) as a runbook step that stays on after the migration.** An issue whose parent is in a
  later batch waits READY_FOR_REWRITE and is linked by the batch that migrates the parent's project;
  every 10 run lists the issues still waiting with the parent's key, per parent project, so a parent
  that never arrives is seen. Batches run with every project migrated so far plus the new ones (the
  jira phases of 07 and 08 replace their staging tables with the run's projects). Runbook section 1.
  Alternatives: (b) a relation instead of a parent across projects, (c) leave such parents out and
  log them.
- **Service desk fields "Organizations" and "Request participants"**: 0 values in GEOS, SIS, OAC and
  WIKI (measured in the 7.0 staging); 08 keeps them MANUAL. Recommendation: Request participants become
  watchers (the nearest Redmine notion: people who follow the request and get its mail), Organizations
  a key/value list field from the used values (as label fields, B45); build it when a project in scope
  uses them. Alternatives: (b) a text field with the names, (c) not migrated.
- **Conversion choices (quality work):** a status lozenge becomes inline code (`` `Closed` ``): a
  separate label close to Jira's box, which cannot mix with bold or links next to it as `**` can
  (alternative: bold); a link to a migrated issue with its own text reads "text (#123)" (the text was
  lost), with the key as text it stays "#123"; a link to an issue outside the migration keeps its Jira
  link with the key as text, readable after Jira closes and clickable while it is open (alternative:
  the key as plain text); Outlook safe links in mailed comments become the link itself (the wrapper
  expires and carries the recipient's address).

## Measured on 2026-10-13 (reading the code, nothing run yet)

| # | finding | where |
|---|---|---|
| M1 | 8 helper functions copied in all 16 scripts (CLI, config, DB, timestamps); Jira/Redmine clients 13 to 15 times | `^function` across `[0-9]*.php`. Clients: ClientFactory (phase 6); Extended API helpers and phase selection shared (B2, work list 11) |
| M2 | No tests of any kind | repo |
| M3 | 429 handling in 4 of 16 scripts (09, 11, 14, 16); in `10_migrate_issues.php` one 429 during the issue search fails the whole project | `10_migrate_issues.php:588` |
| M4 | Gateway URL breaks the Jira client: `base_uri` plus a path with a leading slash (`/rest/api/3/...`) drops `/ex/jira/{cloudId}` | `createJiraClient`, every Jira call |
| M5 | Attachment downloads use the absolute site URL from Jira's `content` field; a scoped token needs the gateway | `09_migrate_attachments.php:441` |
| M6 | Two conversion paths: Jira's rendered HTML via `league/html-to-markdown` (main), ADF via the vendored `Karvaka/AdfToGfm` (fallback), plus ~1000 lines of regex post-processing | `lib/description_conversion.php`, `10_migrate_issues.php:1250` |
| M7 | Mentions: profile links become `user#<id>`; ADF mentions become inline code | `lib/description_conversion.php:557`, `Karvaka/AdfToGfm/src/Nodes/Mention.php` |
| M8 | Manual steps before/after a migration live in CHANGELOG.md: attachments prune off, locked users temporarily activated, LDAP sync off, workflows opened and reset, stealth mode, Apache `RACK_QUERY_PARSER_PARAMS_LIMIT` | `CHANGELOG.md` |
| M9 | Attachments are uploaded for tokens before issues exist; Redmine's prune task deletes unattached uploads older than a day, hence "prune off" in M8 | `09_migrate_attachments.php` push phase |
| M10 | Staging SQL is MySQL-only (`ON DUPLICATE KEY UPDATE`, `VALUES()`); GEOxyz runs no MySQL any more | all scripts |
| M11 | GEOxyz-specific relation types in `config.default.php` | `migration.relations.custom_mappings` |
| M12 | Worklogs (time entries) are not migrated; workflows are export-only | repo. **Worklogs: out of scope (Jan, decision 24), closed.** Workflows: built (decisions 13, 14) |
| M13 | Extended API `main` (what 5.1 runs) still has bugs fixed on its `redmine70-migration`: `notify=false` switches mail off process-wide (`Mailer.with_deliveries(false)`), so other users' mail is lost during an import; unstorable override values are saved as NULL instead of a 422 | `redmine_extended_api` |
| M14 | Large files: Redmine streams uploads to disk in 8 KB blocks and has no hard size cap (`attachment_max_size`, default 5 MB); core has no video player, a video is a download; Apache 2.4.53+ has `LimitRequestBody` 1 GiB by default | Redmine 5.1/7.0 `AttachmentsController`, `Attachment` |
| M15 | Good, keep: ETL with staging, mapping tables, idempotent reruns, `--dry-run`/`--confirm-push`, `automation_hash` preserves manual edits, Jira keys rewritten to `#id`, keyset paging on `/rest/api/3/search/jql` | |

## Work list

In this order. Each item records its result under "Results" when done.

**Phase 0, prerequisites (Jan)**

1. Create the scoped token (id.atlassian.com, Security, API tokens, "Create API token with
   scopes", app Jira, read scopes only) and put it in the cloud environment's settings as
   `JIRA_SITE` (e.g. `geoxyz.atlassian.net`), `JIRA_EMAIL`, `JIRA_API_TOKEN`. Never in the chat or
   the repo. A new session picks it up.
   **DONE** 2026-10-13 (Jan set the variables in the environment; prove read-only per decision 1).
2. Test projects. Jan (2026-10-13): **Survey Support Desk** has the most tickets. The coordinator
   sizes every Jira project first (issues, comments, attachments and their total size) and puts the
   choice to Jan: 2 or 3 small, varied projects for the baseline (rich formatting, large video,
   many custom fields), and Survey Support Desk as the volume run once the baseline is clean.
   **Chosen by Jan (2026-10-13, through the coordinator):**
   - baseline: **GEOS** (97 issues, 547 comments, 320 attachments, 1.0 GB, 29 custom fields),
     **SIS** (265, 477, 199, 0.2 GB, 18 custom fields, a 67 MB video), **OAC** (64, 77, 57, 0.25 GB,
     61 links, 22 subtasks);
   - conversion bench: **WIKI** added (1,457 issues; the only project with panels and most code blocks);
   - volume run: **SURV** (1,494 issues, 7,344 comments, 6,317 attachments, 98.7 GB) with an
     attachment sample: every attachment up to 512 KB, plus per file extension the largest file up to
     20 MB (about 4,305 files, about 1 GB); the rest stays in staging as metadata, is not downloaded or
     pushed, is reported as "skipped by sample" and counted as expected-absent by the verify tool. It is
     a test option, off by default, a fixed rule;
   - large attachments: LABO-562 attachment 33685 (mp4, 431 MB, the largest video in Jira) and
     SURV-267 attachment 11838 (tif, 1,811 MB). No video in Jira is larger than 431 MB.
   Jira as a whole: 15 projects, 4,911 issues, 13,677 comments, 10,095 attachments, about 116 GB.
3. 5.1 plugin set. Jan (2026-10-13): production runs each plugin's default branch, **except**:

   | plugin | branch on 5.1 |
   |---|---|
   | `redmine_custom_workflows` | tag `v2.1.2` (decided by Jan 2026-10-13; `master` and `5.x` are v3.1.1 and need Redmine 6, F2) |
   | `bless-this-redmine-sso` | `feature_version_2.0.0` |
   | `redmine_issue_templates` | `master-geoxyz` |
   | `redmine_subtask` | `develop` |
   | `redmine_ai_summary` | `main-GEOxyz` |
   | `redmine_mermaid_macro` | `mermaid10` |
   | `redmine_wiki_extensions` | `Redmine-5.1` |
   | `redmine_user_specific_theme` | `compatibility-with-redmine-5` |
   | `redmine_issue_view_columns` | `2.0.0` |

   Which plugins make up the set: the Redmine plugins in Jan's GitHub account (public and
   private). Show Jan the derived list once before building the 5.1 instance; on 7.0 every
   plugin uses its `redmine70-migration` branch.

**Phase 1, Extended API on 5.1, 6.1 and 7.0** (repo `redmine_extended_api`, branch
`redmine70-migration`, under that repo's CLAUDE.md and plan)

4. Guard the webhook patch with `defined?(::Webhook)`, lower `requires_redmine` to 5.1, restore
   the test paths removed in `d6013b3`, check Ruby syntax against the oldest supported Ruby.
   Tests on 5.1, 6.1 and 7.0 with PostgreSQL; e2e on each. Record the decision in that plan.

**Phase 2, test environments**

5. Redmine 5.1 with the production plugin set; Redmine 7.0-stable-GEOxyz with the
   `redmine70-migration` branches; PostgreSQL 16 for both; staging database as today (MySQL) until
   the baseline is in. REST API on, text formatting CommonMark on both. After setup, take a
   `pg_dump` of each and restore it before every run.

**Phase 3, minimal change to run at all**

6. Jira gateway support (M4, M5): one place that builds Jira URLs, relative paths, attachment
   downloads through the gateway. Read-only probe (decision 1) recorded with its answer.
   **DONE** 2026-10-13 (session B). Probe: `PUT /rest/api/3/issue/ZZPROBE-999999` and
   `POST .../comment` through the gateway both answer `401 {"message":"Unauthorized; scope does not
   match"}`; `GET /rest/api/3/myself` answers 200. Fix: `lib/jira_gateway.php`, one Guzzle
   middleware on every Jira client, on when `jira.cloud_id` is set; puts `/ex/jira/{cloudId}` back on
   leading-slash paths and moves absolute site URLs to the gateway. Proof: `01 --phases=jira`
   extracts 15 projects through the gateway; an attachment download returns 200 with the exact
   size (Jira redirects to `api.media.atlassian.com`). Tests: `tests/JiraGatewayTest.php`, 3 of 8
   fail without the rewrite.
   **New token (granular read scopes), B2, 2026-10-13:** `GET /rest/api/3/myself` 200;
   `PUT /rest/api/3/issue/ZZPROBE-999999999` and `POST .../comment` 401 "Unauthorized; scope does not
   match"; `role`, `group/member`, `workflowscheme`, `screens`, `workflow/search`, `issuetypescheme`,
   `issuetypescreenscheme`, `screenscheme`, `fieldconfiguration`, `fieldconfigurationscheme`,
   `permissionscheme` 200; the legacy `GET /rest/api/3/workflow` 401.

**Phase 4, baseline**

7. Run every script, otherwise unchanged, on the test projects, to 5.1 and to 7.0. Record every
   failure, warning and manual intervention in "Findings". Fix nothing yet.
   **DONE** 2026-10-13 (session B): two runs per target, see Findings B1 to B23 and the verify table
   there. `tools/testenv/run_all.sh 51|70 LABEL` repeats a full run from a clean Redmine and staging.

**Phase 5, measuring tools**

8. Verify tool: compare Jira and Redmine after a run. Counts per project, tracker and status;
   for a sample of issues the description, journals, attachments (name, size, checksum),
   watchers, relations and custom field values. Output a pass/fail report. From here on "green"
   means this report.
   **DONE** 2026-10-13: `tools/verify.php --projects=GEOS,SIS,OAC [--sample=25]`, rules in
   `lib/verify.php` (21 PHPUnit tests). Live Jira against live Redmine; staging only maps ids.
   Renders `user#id`/`@login` as names and ignores Jira browse URLs (rewritten to `#id` by design),
   as Redmine shows them. A run of 3 projects takes about 2 minutes.
9. Text conversion bench (decision 7), see "Text conversion protocol".
   **Steps 1, 2 and 4 DONE** 2026-10-13: `tools/conversion_bench.php`, results under that protocol.
   Step 3 (visual check with Jan) and 5 (PHPUnit cases) follow in phase 7.
   **B2:** step 5 done with every fix; step 3 pages made on 2026-10-14 (29 issues), Jan's look open.
10. Large attachment test (decision 3), see "Large attachment protocol".
    **DONE** 2026-10-13, results under that protocol.

**Phase 6, code quality without behaviour change**

11. Shared library under `src/` (PSR-4): config, Jira client and Redmine client with central
    retry (decision 5), database, CLI, mapping helpers. Scripts keep their CLI. PHPUnit for the
    pure transformations, PHPStan, PSR-12. Proof of no behaviour change: the push payloads of a
    dry run are identical before and after, on the same staging data.
    **First part DONE** 2026-10-13: `src/` (ClientFactory, RetryPolicy, ConnectionFactory), QA tools;
    a full 5.1 run recorded before and after: 3,918 Redmine requests, 0 differing. Rest: M1 helpers.
    **M1, B2 2026-10-14:** the Extended API helpers (40 copies in 04 to 12, 785 lines) moved to
    `src/Redmine/ExtendedApi.php` without behaviour change (`e5e140d`); 08's own absolute paths replaced in a
    separate commit (`10b810d`, F6: a Redmine under a sub-path); one phase rule for all 16 scripts,
    `src/Cli/Phases.php` (`ec32074`, behaviour change for invalid options only: an unknown phase no longer runs
    every phase in 09, 11, 13 to 16). Left: helpers whose copies behave differently (timestamps in UTC or local
    time, JSON decoding of an empty body, PDO attributes, option parsing per script); each needs a choice, so
    they move one by one with a test per difference. Proof (5.1, 2026-10-14, `RECORD=` runs `b2-dress2` with
    `2853767` and `b2-m1` with `eb53e88`, both with the stability pass, workflows and restore): 7,116 Redmine requests
    before and after, 0 differing; both runs PASS with identical summaries.
    **M1 rest, B2 2026-10-14 night** (the helpers whose copies behave differently), per helper what is right for this
    migration:
    - timestamps: staging and both APIs are UTC, but 08, 09 and 12 (and the log lines of 01 to 09) used the server's
      timezone, and a time without an offset was read in it. **Unified on UTC** (`bfdcc13`): bootstrap sets PHP to
      UTC, ConnectionFactory sets the MySQL session to +00:00. On the way B58 (attachments one or two hours late),
      fixed separately (`004b57b`). The recorded run with both (`b2-utc`) then failed verify on 6 duplicate
      journals: B59, 11's attachment window matched for the first time with the right times; fixed separately
      (`e2ca087`), the comment window is a choice for Jan;
    - JSON decoding of an empty body (null, [] or an error per script): every call decodes a GET or a create
      response, which always has a body; Redmine's empty 204 answers are never decoded. **Kept** per script: each
      fits its callers' types, a change would gain nothing;
    - PDO attributes: the fetch mode differs, but every query names its own mode; native prepares in 01 to 03 give
      integers where the others cast strings. **Kept**: switching risks TypeErrors under strict_types and gains
      nothing;
    - option parsing: every script stops on an unknown option (reported as "Unexpected positional arguments");
      the copies differ only in accepted spellings (01 to 03 also take `--phases jira`). **Kept**.
    One choice for Jan: the comment window of B59 (Open questions). The proof of the behaviour changes is in
    Results (M1 rest).

**Phase 7, fixes**

12. Mentions to `@login` (decision 4), in both conversion paths.
    **DONE** 2026-10-13: HTML path and ADF path; `user#id` stays only for users without a known login.
13. Conversion fixes from the bench, each with a PHPUnit case built from an anonymised
    reproduction of the Jira input.
14. Every finding from the baseline: fixed, or a line saying why not.
    **Status 2026-10-13**, each fix with a PHPUnit test (73 tests) unless noted:
    fixed B1, B3, B4 (`migration.issues.projects`), B6 (link rewrite; MANUAL rows judged again, the
    latter checked end to end only), B7, B8, B9, B10/B11/B22, B13 (setting), B14 (Jan's rule), B15,
    B19, B20, B24, F5; B21 is configuration (`download_concurrency` 6: 760 s to 96 s); open subtask
    under a closed parent: parent gets "Resolved" (Jan). Not code: B2 (token scopes, waiting for Jan's
    new token), B5/B12 (manual steps, item 15), B16 alt text (kept), B17 and B23 (noise; reported),
    B18 (production config of redmine_issue_view_columns, documented in the runbook).
    Run on 7.0 with the fixes (before B24, B14, Resolved and the parent priority setting): 420 of 426
    issues, every tracker/status count equal to Jira, presence 75/75, watchers 960 stored.
    **Full runs with all fixes, B2, 2026-10-13** (`run_all.sh 51|70 b2-full1`, new token, 03 in two
    passes, 04 with push, Extended API 132fff5 on 5.1 and 9867918 on 7.0): **verify green on both**, 21
    checks, 0 failures (presence 75/75, counts 30/30, comments 254, attachments 75/75 and sha256 136,
    custom fields 340, relations 35, watchers 75, every field 75/75). Both targets the same: 426 issues
    (422 created, 4 READY_FOR_REWRITE: parent outside the run), 1,277 journals, 576 attachments, 71
    relations (49 MANUAL: other side outside the run), 960 watchers, 38 project role memberships. Green
    after reruns that needed no SQL: 12 for 17 relations (relation types missing in the rebuilt test
    instances, B34), 09/10/11 on 7.0 for GEOS-23 (B33). Stability check (10 to 14 a second time):
    10, 12, 13, 14 change nothing (13 and 14 caught up GEOS-23); 11 pushed every journal again (B35, fixed).

**Phase 8, workflow and documentation**

15. Runbook for a full migration: order, commands, what to check after each step, where the
    decisions live. Replace the manual steps of M8 where it is worth it, each as a choice for Jan
    with options (examples: upload and attach per issue so prune can stay on; an Extended API
    import mode that skips workflow and initial-status checks and accepts locked users, admin
    only, `/extended_api` only).
    **DONE** 2026-10-13; Jan: manual steps stay scripted, prune off as a runbook step (decisions 8, 9).
16. README rewritten from the runbook; CHANGELOG back to a changelog.
    Status 2026-10-13: done. Runbook in `docs/RUNBOOK.md` (sequence, checks, manual steps with
    `manual_steps.rb` workflows/members/restore, choices for Jan, Apache appendix). README is a short
    overview; the per-script detail moved to `docs/SCRIPTS.md` (subtask, watcher and attachment name
    notes brought up to date); CHANGELOG lists the changes of this branch, its manual steps (M8) are
    in the runbook. Open: the Extended API import mode (choice for Jan, session A).

**Phase 9, final runs**

17. Full test run on both targets with the final code; verify report green; numbers in "Results".

## Sessions

The work runs in parallel sessions that write **disjoint repositories**, so they never conflict.
Exactly one session writes this file: session B until 2026-10-13, B2 from then on (see Handover).

**Coordinator** (the session Jan starts; it has the Jira variables). It does not build. It:

1. Checks that `JIRA_SITE`, `JIRA_EMAIL` and `JIRA_API_TOKEN` exist (names only, never print a
   value), looks up the cloudId (`https://$JIRA_SITE/_edge/tenant_info`) and proves the token
   read-only with the write probe of decision 1. A token that is not refused: stop and tell Jan.
2. Sizes every Jira project (issues, comments, attachments, total attachment size) through the
   gateway, and puts the test project choice to Jan (work list 2).
3. Starts sessions A and B with `create_session`: same environment (so they inherit the Jira
   variables), the prompts below with its own session id filled in, tag `jira-migration`.
4. Follows them (`get_session`, `list_events`). Puts every question a session sends to Jan as a
   clickable multiple choice: the options, a plain explanation of each, and a recommended option.
   Sends Jan's answers back with `send_message`. Sends B the chosen test project keys.
5. Tells Jan, in Dutch and briefly, when a session finishes a phase, is blocked, or fails; never
   does a session's work itself.

| session | repository, branch | writes | starts |
|---|---|---|---|
| A | `jcatrysse/redmine_extended_api`, `redmine70-migration` | that repository only | at once |
| B | `jcatrysse/jira2redmine_migration`, `redmine70-migration` | this repository only | at once; phase 4 waits for the test project keys |

Prompt A:

> Read CLAUDE.md and docs/REDMINE7-MIGRATION.md. New decision by Jan (2026-10-13), revising the
> 2026-10-07 "Redmine 7 only" for this plugin: make it compatible with Redmine 5.1, 6.1 and 7.0.
> Apply the webhook patch only when ::Webhook is defined, lower requires_redmine to 5.1, restore
> the 5.1 test paths removed in d6013b3, keep the Ruby syntax valid on the oldest supported Ruby.
> Tests and e2e on 5.1-stable, 6.1-stable and 7.0-stable-GEOxyz with PostgreSQL. Record the
> decision and the results in docs/REDMINE7-MIGRATION.md. Context: jcatrysse/jira2redmine_migration,
> branch redmine70-migration, docs/MIGRATION-PLAN.md, work list 4. Questions for Jan go to the
> coordinator session <id> with send_message; meanwhile pick the option most likely right, log it
> as open, and carry on. Report in Dutch at the end, also to the coordinator.

Prompt B:

> Read CLAUDE.md and docs/MIGRATION-PLAN.md, then carry out the work list from phase 2 on
> (phase 0 and 1 belong to the coordinator and session A). For the 5.1 baseline use
> redmine_extended_api `main` (what production runs); rerun on its redmine70-migration branch
> once session A is done. Questions for Jan go to the coordinator session <id> with send_message;
> meanwhile pick the option most likely right, log it as open, and carry on. Report in Dutch at
> the end of each phase, also to the coordinator.

## Text conversion protocol

1. Curated set: real issues chosen per construct (tables, code blocks, panels, nested lists,
   inline images, attachments links, mentions, issue links, task lists, headings, quotes, colour,
   emoji, dates, expand), from the test projects. List the issue keys used here.
2. Automatic check over all migrated issues and comments: the plain text of Jira's rendered HTML
   against the plain text of Redmine's own CommonMark rendering of the converted Markdown. Report
   issues that lose words, links or images. The result is a number: x of N without loss.
3. Visual check for the curated set: Jira and Redmine rendering side by side, shown to Jan (not
   committed, see Rules).
4. Both paths (HTML and ADF) are measured; the plan records which one is better per construct.
5. Every defect found becomes a PHPUnit case with an anonymised input and the expected Markdown.

**Results 2026-10-13 (session B).** Every staged description and comment, rendered by Redmine 5.1's
own CommonMark formatter, against Jira's rendered HTML. "Without loss": at least 99% of Jira's words,
no fewer links or images, the element of each construct present (table, pre, heading, blockquote,
nested list, mailto, img), and no visible Markdown syntax (`**`, `\|`, `](`, `|---`, HTML tags).

| set | path | items | without loss |
|---|---|---:|---:|
| GEOS, SIS, OAC, WIKI (bench staging) | html (main) | 4,648 | 4,630 (99.6%) |
| | adf (fallback) | 4,648 | 2,098 (45.1%) |
| GEOS, SIS, OAC (run 2 staging) | html | 1,444 | 1,440 (99.7%) |
| | adf | 1,444 | 747 (51.7%) |
| | pipeline (what is pushed) | 1,139 | 1,057 (92.8%) |

- **Which path:** the HTML path is better for every construct; ADF loses links (2,313 items), code
  blocks (31 of 33), images and mentions. Keep HTML as the main path; ADF only where Jira gives no HTML.
- **The losses are in the pipeline after the converter** (phase 7): inline images in comments become
  plain attachment links (46 of 54 items with attachment links), mentions glued to the next word
  ("user#12thanks"), `mailto:` domains lost (B15), Markdown links that do not render (`](` visible, 11),
  a heading and quote lost in one comment, `**` visible inside raw HTML blocks.
- HTML path defects (18 of 4,648): WIKI tables written in MediaWiki syntax and `<nowiki>`/`<pre>` text
  that Jira itself shows as text (WIKI-280, -282, -294, -295, -843), raw HTML left in 6 items.
- Constructs absent from these projects: task lists, dates, expand. Present (issue counts): mention
  664, image 311, issue link 205, attachment link 111, nested list 61, panel 39, code block 30,
  table 28, email link 24, emoji 23, heading 16, status lozenge 14, colour 11, quote 10.
**Results B2, 2026-10-13**: a run on 7.0 with GEOS, SIS, OAC and WIKI (1,883 issues, 3,535 comments,
`attachments.test_sample`: 1,103 of 1,413 files), every staged item rendered by Redmine 5.1:

| path | items | without loss | B (earlier) |
|---|---:|---:|---:|
| html (converter only) | 4,648 | 4,572 (98.4%) | 4,630 (99.6%) |
| adf | 4,648 | 4,270 (91.9%) | 2,098 (45.1%) |
| pipeline (what is pushed) | 3,842 | 3,702 (96.4%) | 1,057 of 1,139 (92.8%, three projects) |

- Pipeline losses (140): 98 only "fewer than 99% of the words" (Jira keys rewritten to `#id` by design,
  names of users that are not migrated, a few link labels), 22 with `](` visible, 7 missing images, 5
  with raw HTML tags, 6 with `**` visible, 1 heading and quote lost. Weakest constructs in the pipeline:
  issue link 144/215, status lozenge 3/14, heading 5/12.
- `](` visible: link markup that Jira showed as text (`[label|url]` and `[label](url)` typed by a user)
  half converted by the link rewrites, and URLs with parentheses: **fixed, B42** (8 descriptions and
  part of the 14 comments; the rest are e-mail-originated comments with Outlook safelinks). Avatars
  reached Redmine in 3 issues and 3 journals: descriptions fixed with B41, journals open.
- The HTML path alone dropped from 99.6% to 98.4% since B's measurement: the mention conversion now
  writes `@login`, which the bench renders unresolved on the html path (the pipeline path resolves it).
- Not yet done: a pipeline measurement after B41/B42 on a fresh run, bench step 3 (visual check with
  Jan), the remaining defect classes as PHPUnit cases.

**Results B2, night 2026-10-14 (end quality work).** Every staged item rendered by Redmine 5.1.

| set | code / measure | pipeline without loss |
|---|---|---:|
| GEOS, SIS, OAC, WIKI (WIKI batch staging) | before the fixes / old measure | 3,636 of 3,781 (96.2%) |
| GEOS, SIS, OAC (5.1 final run) | after the fixes / old measure | 1,410 of 1,444 (97.6%) |
| GEOS, SIS, OAC (5.1 final run) | after the fixes / corrected measure | 1,432 of 1,444 (99.2%) |
| GEOS, SIS, OAC, WIKI (7.0 final batch) | after the fixes / corrected measure | 4,566 of 4,648 (98.2%) |

- **Issue links, split as asked** (73 lossy items with an issue link before the fixes): the link's own
  text was dropped in 44 (the only cause in 26): **fixed**, "text (#123)"; the Jira key replaced by
  `#123` in 20 (only cause in 9): by design, Redmine shows the subject on hover; a link to an issue
  outside the migration in 18, never the only cause: the link stays a Jira link with the key as text,
  readable after Jira closes and clickable while it is open. The other missing words were link titles
  (fixed with the text rule) and MediaWiki text (a separate class). After the fixes, GEOS/SIS/OAC have 5
  lossy items with an issue link, all "key replaced by #id".
- **Headings**: the stray heading anchors wrapped whole texts in `<p>` (13 texts shown as Markdown):
  **fixed** (3 left, literal MediaWiki HTML); empty Jira headings are no longer counted as headings.
- **Status lozenges**: now inline code next to the reference ("#170 `Closed`"); the lozenge items still
  count as lossy only for the key replaced by `#id`.
- **Safe links**: 30 wrapped links in 11 mailed items, 0 after conversion.
- **Measure corrections** (each tested, both measures reported above): the issue type icon of an issue
  macro is no image, an empty heading is no heading, an Outlook wrapper URL is no text, "#123" inside a
  link label is not linked again (on the Redmine pages of the 7 issues concerned, "](" shows 0 times),
  and a mention of a login that is an e-mail address (all 417 logins are) is a link (on SIS-21's page
  both such mentions render as links with the name).
- Four projects after the fixes: issue link 250/286 (was 144/215), heading 13/13 (was 5/12), status
  lozenge 8/14 (was 3/14). The 36 lossy items with an issue link: key replaced by `#id` 20 (only cause
  in 18), MediaWiki and other text 17, a link's own text 5 (never the only cause), a target outside the
  migration 2. Other WIKI classes: literal MediaWiki HTML (`<tag>` shown, 6), `**` shown (4).
- Remaining in GEOS/SIS/OAC (12 of 1,444): the Jira key replaced by `#id` (5, by design), `**` shown
  inside raw HTML (3), a word glued to an attachment reference (2), one table row and one literal link
  markup shown as text.

**Results B2, 2026-10-15 (decision 26).** Every staged description and comment (4,648 texts of GEOS, SIS,
OAC and WIKI; 1,444 of GEOS, SIS, OAC on 5.1), rendered by Redmine 5.1, "pipeline" = what is pushed. Three
measures: *old* as before; *keys* also reads a Jira key in a link to a migrated issue as the "#123" it
becomes by design (Jan); *corrected* also leaves out what Jira and Redmine show alike (see below). Before:
the 7.0 baseline batch with the code before decision 26 (`458bf21`, run `70-b2-convbase`); after: fresh
runs with the final code (`bc533bf`: 5.1 `51-b2-d26`, 7.0 `70-b2-d26` and the cumulative WIKI batch
`70-b2-d26-wiki`, all verify **PASS**, stability unchanged, restore proofs 12/12 and 101/101, workflows
+0 -0 on the second run). Before and after with the same measure version (`332198f`), then after with the
final measure:

| pipeline, without loss | old | keys | corrected |
|---|---:|---:|---:|
| 7.0, four projects, before (old code) | 4,580 of 4,648 (98.5%) | 4,598 (98.9%) | 4,615 (99.3%) |
| 7.0, four projects, after, same measure | 4,597 (98.9%) | 4,617 (99.3%) | 4,634 (99.7%) |
| 7.0, four projects, after, final measure | 4,604 (99.1%) | 4,624 (99.5%) | **4,647 (100.0%)** |
| 5.1, GEOS, SIS, OAC, after, final measure | 1,435 of 1,444 (99.4%) | 1,440 (99.7%) | **1,444 (100.0%)** |

- **The 44 texts between the old measure and 100% (7.0, final code), by class:** the Jira key rewritten to
  "#id" by design 20; words around an attachment link, inline code or typed link markup that Jira glues
  in its HTML and both systems show apart, or in place 12; syntax Jira's own text shows too ("**" typed in
  a mail, "<br/>" quoted in a sentence, "[...|url]" Jira could not render) 7; MediaWiki source shown as a
  code block instead of Jira's broken table (B64, decision 26; one of them also with syntax Jira shows) 4; a
  link inside a link counted twice in Jira's HTML (B70) 1. **Left: 1** (WIKI-1160): a link Jira's user put in the middle of an italic word
  ("*Qins*[y]"), shown as "Qins (#1571) y".
- **Fixed** (each with an anonymised PHPUnit case that fails without the fix; Findings B60 to B70): ADF
  tables with merged cells and ADF code blocks; bold and italic that Jira splits or nests, also around a
  link; the text "<pre>" in code blocks; inline code Jira showed as "{{...}}" (21 spans in 7 texts);
  MediaWiki source as a code block with its exact text (WIKI-280, -282, -294, -295); a profile link that
  swallowed the text before it (15 texts, whole sentences); typed link markup that lost its spacing (30
  texts) or did not convert with brackets in its label (4); a mention glued to a link; bare addresses with
  backslashes (broken links); a link inside a link; an issue reference followed by a letter, which Redmine
  does not link (7 texts). The bench's corpus checks showed no text changed beyond those classes.
- **MediaWiki (decision 26, part 2):** converted, conservatively: a top-level paragraph that is MediaWiki
  source without doubt (a table "{| ... |}", or `<pre>`, `<syntaxhighlight>`, `<source>` over several
  lines) becomes a code block with the exact source from the ADF, where Jira rendered a broken table. It
  is shown as code, not converted to a Markdown table: these texts document template code ("is replaced
  with the following code"), and the cells hold `<pre>` blocks and templates that a table cannot hold
  without guessing. Left as Jira shows them (no guessing): `'''bold'''`, `[[links]]` and `{{templates}}`
  in a sentence (WIKI-205, -291, -316, -421 and the texts that name a template), tags quoted in a sentence
  ("there should be no <br/>", WIKI-255 and 6 comments): Jira shows them as text, so does Redmine.
- **Measure corrections**, each tested, each a case where Jira and Redmine show the same thing: the key to
  "#id" rewrite only in links to the issue, not in an address, also in lower case; board links that select
  an issue left out like browse links; an attachment link a word of its own (pipeline); visible syntax
  Jira's own text shows; inline code a word of its own; typed link markup read in place, also with
  brackets in its label; "#123" linked where Redmine's own rule links it; a link inside a link once; no
  table expected where MediaWiki source became a code block.
- Visual check (step 3), 2026-10-15: 43 side-by-side pages from the final 7.0 WIKI batch (the curated set
  below plus the decision 26 items), outside the repository, handed to Jan in B2's session. The tool now
  loads Redmine 7's lazy inline images first (they were blank in the screenshots).

- Curated set (step 1), two or three per construct: table GEOS-10, OAC-39, SIS-12; code block SIS-249,
  WIKI-66, WIKI-843; panel WIKI-161, WIKI-185; nested list GEOS-15, OAC-19; image GEOS-87, OAC-47;
  attachment link GEOS-8, GEOS-23; mention GEOS-10, SIS-162; issue link GEOS-65, OAC-16; heading
  GEOS-102, WIKI-254; quote GEOS-10, OAC-17; colour WIKI-185, WIKI-406; emoji GEOS-22, SIS-151; status
  lozenge SIS-85, WIKI-13; email link GEOS-41, SIS-211; MediaWiki text WIKI-280, WIKI-294. Decision 26
  added: MediaWiki source WIKI-282, WIKI-295; emphasis GEOS-53, GEOS-103, WIKI-1373; swallowed text
  WIKI-724, WIKI-625; typed links WIKI-613, WIKI-400; inline code WIKI-561; mention and link WIKI-37; link
  inside a link WIKI-520; issue reference WIKI-42, WIKI-594.
- Step 3 (B2, 2026-10-14): 29 side-by-side pages of the curated set (Jira's rendered HTML on the left,
  a screenshot of the Redmine 7.0 issue page on the right), in B2's session only and handed to Jan
  there; nothing committed. The first version showed descriptions only, while 14 of the 29 constructs
  sit in a comment; the tools now put every migrated comment next to its journal. Checked: GEOS-10's
  table and its five separate numbered lists match Jira; SIS-85 shows "#192 `New`" and the closed #194
  struck through. To make them again after a run:
  `php tools/testenv/visual_items.php OUT/items.json table=GEOS-10,OAC-39 ...`, then
  `REDMINE_URL=... OUT=OUT ITEMS=OUT/items.json node tools/testenv/visual_check.mjs`, with OUT outside
  the repository (the pages hold Jira data).

## Large attachment protocol

On 5.1 and 7.0, with `attachment_max_size` raised (e.g. 4 GB) and SharePoint off:

- files: one or two real large videos from the test projects; if none is large enough, one
  synthetic 2 GB file;
- measure: upload time through the API; memory of the Redmine process during the upload; memory
  of the PHP script; issue page load time with the attachment (browser); download time; whether a
  range request is honoured (206 or 200, i.e. whether a video can be seeked), with and without
  X-Sendfile where the test server allows it;
- list what only production can show: Apache `LimitRequestBody` (1 GiB default since 2.4.53),
  proxy timeouts, X-Sendfile, backup size.

**Results 2026-10-13 (session B)**, `tools/testenv/large_attachment_test.sh`, Puma directly (no
Apache), `attachment_max_size` 4 GB, the same Guzzle streaming upload as 09:

| | 5.1 video 431 MB | 5.1 tif 1,811 MB | 7.0 video 431 MB | 7.0 tif 1,811 MB |
|---|---|---|---|---|
| upload through the API | 4.7 s | 17.2 s | 4.8 s | 23.0 s |
| PHP peak memory | 4 MB | 4 MB | 4 MB | 4 MB |
| Redmine RSS during upload | 270 to 378 MB | +47 MB | 435 to 577 MB | 460 to 670 MB |
| stored size, sha256 of the download | equal, equal | equal, equal | equal, equal | equal, equal |
| download | 1.6 s | 4.9 to 15.7 s | 0.6 s | 0.9 to 2.8 s |
| **Redmine RSS during download** | grows | **275 to 2,087 MB, stays** | flat | **284 to 285 MB** |
| range request `bytes=1000-1999` | 200, whole file | 200, whole file | 200, whole file | 200, whole file |
| issue page load (Chromium) | 0.7 s | 0.4 s | 0.6 s | 0.4 s |

- Upload works on both up to 1.8 GB; the scripts stream (4 MB PHP) and Redmine writes to disk.
- **5.1 reads a download into memory** and keeps it (Puma, Rails 6.1): a 1.8 GB file costs 1.8 GB
  RSS per worker. 7.0 streams. In production Apache with X-Sendfile would serve the file itself:
  check whether X-Sendfile is on for 5.1 before large files go in.
- **No range requests** without a web server in front: a video cannot be seeked in the browser; with
  Apache and X-Sendfile Apache answers ranges (206).
- Only production can show: `LimitRequestBody` (1 GiB default since Apache 2.4.53, so the 1.8 GB
  upload fails behind a default Apache), proxy and Passenger timeouts for a 20 to 30 s upload,
  X-Sendfile, backup size.
- **Jan 2026-10-13:** production 5.1 runs Apache with defaults: no X-Sendfile, `LimitRequestBody`
  1 GiB, so a migration onto 5.1 cannot upload the 1.8 GB file (and 5.1 holds every download in
  memory, see above). The new server is configured optimally: Apache in front of the 7.0 test instance
  with X-Sendfile and a `LimitRequestBody` sized for the largest attachment is measured next, and the
  recommended configuration goes into the runbook. To do.

## Rules

- Run things yourself and quote the numbers. Never call something green you did not see green.
- Jira is read-only: scoped read token, proven at the start of every session that uses it.
- **This repository is public. No Jira data in it**: no payloads, staging dumps, attachments,
  real issue text in fixtures, or screenshots of real issues. Test fixtures are anonymised
  reproductions. Evidence with real data stays in the session and is shown to Jan, not committed.
- Secrets only in the environment or `config/config.local.php` (gitignored); never in logs or
  output.
- Behaviour-preserving refactoring first, behaviour changes in separate commits; every fix gets a
  test that fails without it.
- The scripts stay dual-target (Redmine 5.1 and 7.0); a difference between the two is detected,
  not configured by hand.
- Commits as Jan Catrysse, one subject line saying what and why; never push to `main`.

## Open questions for Jan

- ~~**For the rehearsal on an existing Redmine** (decision 30; docs/PRODUCTION.md, B6; asked through the coordinator,
  2026-10-15)~~ **Jan: decisions 33, 34 and 35.** (1) are the roles that create issues in GEOxyz's Redmine called Manager and Developer
  (`manual_steps.rb workflows` opens the transitions for those two names)? (2) the workflow runner makes the union
  the global workflow of every tracker and role it plans, also on trackers existing projects use: write only each
  project's own workflow there (B2's advice, a small change), or accept and review the preview? (3) B73: the fix,
  and where new priorities go relative to the existing ones.
- ~~Test projects (work list 2).~~ **Jan: TEST is not migrated** (decision 22).
- ~~PHP version on the machine where the scripts will run in production.~~ **Jan: PHP 8.2 or newer** (decision 23).
- ~~Staging database: stay on MySQL or move to PostgreSQL.~~ **Jan: a temporary MySQL/MariaDB** (decision 23).
- ~~Worklogs to time entries: in scope or not.~~ **Jan: out of scope** (decision 24).
- Which manual steps to automate (decide after the baseline, per step).
- B14 measured on 7.0 (2026-10-13): Redmine stores, lists and downloads same-named attachments
  correctly, but an inline image resolves to the latest attachment of that name (description showed
  #578 instead of #577). **Jan: original names, `{jiraId}__` only for a name the issue already has.**
  Implemented; with that rule all four inline references of the test issue resolve to the right file
  on 7.0 and on 5.1 (`tools/testenv/duplicate_names_test.php --rule`); without it both Redmines show
  the second file for both references.
- Open subtask under a closed parent, **Jan:** link it and give the parent the open status "Resolved"
  (exists and is open on 5.1 and 7.0, id 3). Implemented as the default rule; parents noted
  `[open-parent]` with their Jira status; the verify tool expects Resolved for them. In the baseline
  data: OAC-19 and OAC-42.
- ~~Granular read scopes~~ **done**: the new token works (B2, work list item 6). (Approved by Jan 2026-10-13: read:group:jira, read:user:jira,
  read:project-role:jira, read:workflow:jira, read:workflow-scheme:jira, read:issue-type-scheme:jira,
  read:issue-type-screen-scheme:jira, read:screen:jira, read:screen-scheme:jira, read:screen-tab:jira,
  read:screenable-field:jira, read:field-configuration:jira, read:field-configuration-scheme:jira,
  read:avatar:jira): waiting for the new token; then write probe again and rerun 03, 04, 16.
  Correction (coordinator, checked against Atlassian's OpenAPI spec 2026-10-13): a granular token needs
  every scope an operation lists; the 14 missed 7. Jan gets the union of 21 granular scopes plus
  read:jira-work and read:jira-user (list in the runbook, step 0).
- 16 against the current spec (to confirm with the new token): `GET /rest/api/3/workflow` is gone from
  the spec (16 calls it after workflow/search; expect an error that stops the export, fix with a test
  once the real answer is seen); workflow/search, fieldconfiguration and fieldconfigurationscheme are
  deprecated; `/rest/api/3/automation/rules` is not a Jira platform endpoint (16 already skips it on
  404). Logged as B25. **Confirmed and fixed (B2)**: see B25 to B28.
- ~~Project memberships (B31)~~ **Jan: option (a)**, see decision 10.
- ~~**Comment attachment window (B59, 2026-10-14 night).**~~ **Jan: 0** (decision 27). The original 11 listed under a Jira comment, as
  "> attachment:" lines, every file of the issue uploaded within 5 minutes of the comment. With B58 fixed that
  gave 199 of the 1,277 comments of the 5.1 test run such lines, mostly files the comment already shows (267 of
  the 390 listed); the issue's attachment list shows every file anyway. 11 now has the setting
  `migration.journals.comment_attachment_window` (seconds). **Recommendation: 0 (off, the default)**; 300 gives
  back the original 5 minutes. Status changes no longer take part: they list only the files their items name.
- ~~B37, B38/B39~~ and ~~automation rules~~: decided overnight, confirmed by Jan 2026-10-14 (decisions
  15 to 17).

## Findings

Before the baseline (session B, 2026-10-13):

| # | finding | effect | action |
|---|---|---|---|
| F1 | `5.1-stable-GEOxyz` sets `session_store :active_record_store` but its Gemfile lacks `activerecord-session_store` (7.0 has it) | 5.1 does not boot from the repo alone | Jan: Gemfile.local through the test setup, nothing committed to the Redmine fork (done in `setup.sh`) |
| F2 | `redmine_custom_workflows`: Jan says production 5.1 runs `main`; the repo has no `main`, and `master` (2aacc23) and `5.x` (38d6a53) are v3.1.1 with `requires_redmine 6.0.0`: "PluginRequirementError ... requires Redmine 6.0.0 or higher but current is 5.1.13.stable" | does not load on 5.1 | **decided**: tag `v2.1.2`. Custom workflow scripts run on issue save through the REST API, so they are part of an import; the test instances have none of production's scripts |
| F3 | `redmineup` >= 1.1.5 defines route `auto_complete_taggable_tags`, `redmine_contacts` master too | 5.1 does not boot with the newest gem | test env pins `redmineup` 1.1.4 in the lockfile |
| F4 | `issue_recurring` loads models from its rake file | `rake db:migrate` fails on an empty database | setup moves it aside for the first migrate |
| F5 | Jira client with a scoped token on the site URL (no gateway) | `01 --phases=jira` reports "0 project records processed" and exits 0, no error | phase 7: an empty or unauthorised extraction must fail loudly |
| F6 | Redmine calls use leading-slash paths too (`/roles.json`, `/issues/%d.json`, ...) | a Redmine under a sub-path (`https://host/redmine/`) would lose the sub-path, same bug as M4 | phase 6 (shared Redmine client) |
| F7 | Through the gateway Jira returns attachment `content` URLs on the gateway already | M5 only matters for staging rows extracted through the site URL; the rewrite covers both | none |

**Baseline (phase 4), session B, 2026-10-13.** GEOS, SIS, OAC (426 issues, 1,101 comments, 576
attachments) to Redmine 5.1 (Extended API `main` @ 132fff5) and 7.0, scripts unchanged apart from the
gateway. Run 1 followed the scripts and their defaults; run 2 applied today's documented manual steps
up front (`tools/testenv/manual_steps.rb`: open workflows, activate users and add them to the projects
as a stand-in for 04, which the token cannot run yet; `view_watched_issues` for watchers) and the
documented config option `default_redmine_author_id`. Logs, staging and reports stay in the session
(real data). **5.1 and 7.0 give the same numbers, except B19.**

Verify, 25 sampled issues per project (75; 62 exist in Redmine):

| check | run 1 | run 2 | cause in run 2 |
|---|---|---|---|
| presence | 62/75 | 62/75 | B7, B8 (issues not created) |
| counts total / per tracker+status | 0/3, 0/37 | 0/3, 18/29 | issues not created |
| status | 1/62 | 62/62 | run 1: B12 |
| watchers | 2/62 | 62/62 | run 1: B20 |
| subject, tracker, author, assignee, created | 62/62 | 62/62 | |
| parent | 60/62 | 58/62 | B10, B22 |
| priority | 61/62 | 60/62 | B13 (derived from subtasks) |
| description words / links / images | 58, 60, 61 of 62 | 58, 59, 60 of 62 | B10, B15, B22 |
| comments (journal holds >= 95% of the words) | 142/145 | 142/145 | B15 |
| attachments name+size / sha256 | 62/62, 95/95 | 62/62, 95/95 | |
| attachments original filename | 37/62 | 37/62 | B14 |
| relations | 27/32 | 27/32 | B18 |
| custom fields (value present) | 66/97 | 66/97 | B7 |

Run 2 totals per target: issues 365 created + 6 waiting for a parent + 2 update failed, 48 failed,
5 manual; journals 939 pushed (749 belong to issues not created); attachments 367 attached, 208
uploaded but never attached (issues not created; Redmine's prune removes them); relations 60 created,
60 manual; watchers 777. Verify on both targets: **FAIL, 106 failures**.

| # | finding | effect | where |
|---|---|---|---|
| B1 | Tracker name longer than 30 characters ("Automated Feedback from Jira tickets") | 422 on create; custom field "Department" then MANUAL (missing tracker) | 07 transform |
| B2 | Scope gaps: `group/member`, `role`, `workflow*`, `issuetypescheme*`, `screens`, `fieldconfiguration` answer 401 | 03 and 04 stop at the first 401 (exit 1); no group members, no project roles, no workflow export | token (Jan approved read scopes), 03, 04, 16 |
| B3 | 143 of 587 Jira accounts MANUAL: 111 app accounts, 32 people without a visible e-mail | their issues need `default_redmine_author_id`; app accounts should be ignored | 02 transform |
| B4 | No project selection: 01 creates every project, 10 stamps `issues_extracted_at` on every project although the JQL keeps 3 | adding a project later needs a manual reset of that column | 01, 10 |
| B5 | New users are LOCKED by default and 04 cannot add members | 283 issues "Assignee is invalid" (run 1) | 02 default, 04 |
| B6 | Retry needs SQL: rows in MANUAL or CREATION_FAILED are skipped on rerun, failed rows show as "[preserved] manual overrides" (25) though nobody edited them; same in 12 | every retry after a fix means `automation_hash = NULL` by hand | 10, 12 transform |
| B7 | Cascading fields (depending_enumeration): 08 stores value dependencies as labels, 10 sends enumeration ids | 44 issues 422 "is not included in the list"; on created issues the child value is empty (31 of 97 sampled values) | 08 push, 10 transform |
| B8 | Jira due date before the start date (start = created date) | 5 issues 422 | 10 transform |
| B9 | Unmapped assignee makes the issue MANUAL instead of unassigned | 4 issues not created | 10 transform |
| B10 | Description and parent are written in a second "final update"; until then the description is empty. One run of 10 leaves 373 issues READY_FOR_REWRITE; a second run is needed; issues whose parent failed keep an empty description | empty descriptions (GEOS-87: 0% of words) | 10 push |
| B11 | The final update writes the description as an update | every issue gets a journal "Description changed" by its author at the creation time | 10 push |
| B12 | Issues are created in the default status: new trackers have no workflow | 61 of 62 sampled issues "New" (run 1); fixed by opening workflows (a manual step) | 10, M8 |
| B13 | Redmine derives a parent's priority (and dates, done ratio) from its subtasks by default | OAC-6, OAC-52 differ from Jira | **Jan 2026-10-13: parent priority independent** on both targets (Redmine setting); verify expects Jira's priority |
| B14 | Attachments are stored as `{jiraId}__{name}` with spaces and special characters replaced | 25 of 62 sampled issues show other file names than Jira; keeps inline references unique | 09, 10; choice for Jan |
| B15 | `mailto:` links become `<local-part>` (domain lost); an HTML block (`<p>...`) around Markdown leaves `**` visible | GEOS-41, OAC-24, OAC-39, SIS-211 | conversion pipeline (not the HTML converter, see bench) |
| B16 | Mentions become `user#id`; ADF mentions inline code (M7); image alt text dropped | as planned for phase 7 | conversion |
| B17 | Journals of issues not created stay PENDING without a note; 31 warnings "changelog mentions attachments but none were mapped" (attachments deleted in Jira) | noise, no reason recorded | 11 |
| B18 | GEOxyz relation types need `redmine_issue_view_columns.local.rb` (production config, not in a repo); 9 "blocks" relations MANUAL ("blocked issue already closed"), 51 MANUAL because an issue is missing | 15 relations 422 without that config (test env now generates it from `custom_mappings`) | 12, test env |
| B19 | **7.0 only:** `redmine_tags` `redmine70-migration` dropped `POST /issues/:id/tags.json`; tags go through `issue[tag_list]` | all 32 tag updates 404 on 7.0 | 13 (detect, do not configure) |
| B20 | `redmine_view_issue_description` only accepts watchers with `view_watched_issues`; Redmine still answers 204 | 14 reports 777 SUCCESS while no watcher is stored (run 1) | 14 must read back |
| B21 | Downloads: 09 pulls 1.5 GB in about 800 s (about 1.9 MB/s); curl on the same gateway 17 MB/s | the SURV volume run is slow | 09 pull |
| B22 | An open subtask under a closed parent: 422 "An open issue cannot be attached to a closed parent task" on the final update, and the description in that same update is lost | OAC-44, OAC-47: no parent, empty description | 10 final update, `subtask_status_behavior` |
| B23 | Uploads happen before the issue exists (M9); for issues that fail, the upload stays orphaned | 208 orphans | 09, 10 |
| B25 | 16 calls `GET /rest/api/3/workflow`, which is no longer in the Jira Cloud spec; workflow/search, fieldconfiguration and fieldconfigurationscheme are deprecated (coordinator, from the OpenAPI spec) | seen with the new token: 401 "scope does not match", the export stops; so do `GET /issuetypescheme/{id}` (no GET in the spec; 405 with a classic token, skipped) and `/automation/rules` (not a platform endpoint) | **fixed** (B2): workflow/search only, the scheme's issue types from `/issuetypescheme/mapping`, no automation call; 16 exit 0 |
| B26 | workflow/search returns the name in `id.name` (an object); 16 read `name` | all 64 workflows stored as "unknown", one row left | **fixed**, `src/Jira/WorkflowSearch.php`; with `expand` the export holds transitions (856), rules and statuses |
| B27 | Paging advances by the requested page size; Jira caps workflow/search at 50 when asked for 100 | 14 of 64 workflows skipped | **fixed** in 16 (`src/Jira/Paging.php`); 11 pages comments and changelog the same way, Jira honours 100 there today (checked), latent |
| B28 | `issuetypescheme/project` nests the scheme (`issueTypeScheme.id`, `projectIds`); 16 read flat keys | 0 project to scheme rows | **fixed**, 15 rows |
| B30 | Jira returns project role URLs on the gateway; 04 uses their path, the gateway rewrite added `/ex/jira/{cloudId}` a second time | all 45 role requests 401, 04 extracted no actor and exited 0 with warnings | **fixed** in `lib/jira_gateway.php` (a prefixed path keeps one prefix), 278 actors |
| B31 | 04 gave a project role held by a user to every group the user is in | one admin in a 254-member group made it admin (Manager) of 8 projects, 143 group assignments | **fixed** for what both options share: user actors are direct members, group actors their group; `migration.roles.mappings` (Administrators => Manager, add-ons role ignored); app accounts IGNORED; a pushed membership stays recorded on a rerun. Result: 36 users and 2 groups (max 6 members) Manager, as in Jira. Base access: **Jan option (a)**, decision 10: proposals from the permission schemes, reviewed before the push; the push adds a role to an existing membership (PUT) instead of a second POST (422). 5.1: 89 proposals, 2 hand edits kept on a rerun, 88 pushed, 0 failed |
| B32 | 03 adds group members only once the groups exist; the runbook ran it once | 754 members AWAITING_GROUP, no group had members | runbook and `run_all.sh`: a second pass (551 members added; 203 wait for accounts that are not migrated) |
| B33 | A download that fails (a dropped tunnel to api.atlassian.com, two runs in parallel) is FAILED; the transform requeued it and cleared the reason; 10 holds the issue back | 7.0: 2 of 576 files, GEOS-23 not created, no visible cause | **fixed**: 09 tries failed files once more in the same pull, prints each failure with its reason and keeps it when requeued (`src/Attachments/PullRetry.php`); 10 no longer waits for a switched-off attachment (`IssueAttachmentState`) |
| B34 | 12 marks a refused relation CREATION_FAILED without its automation hash | every later transform takes it for a hand edit ("preserved manual overrides") and never retries; 17 relations after the relation types were added | **fixed** (`src/Relations/RelationState.php`); proof on 5.1: 17 failed, a plain rerun created all 17. Test env: `setup.sh` now installs the relation types (B18) |
| B35 | **11 pushes every journal again on each rerun** (also on `main`): the transform set every journal of a created issue to READY_FOR_PUSH, pushed ones included; FAILED journals were never retried | stability check: +1,277 duplicate journals on 5.1 and 7.0; verify still passed (each Jira comment was found) | **fixed** (`src/Journals/JournalStatus.php`): pushed journals stay SUCCESS, FAILED and SKIPPED are judged again; proof on 5.1: rerun pushed 0 comments, journal count unchanged. verify now fails on a journal present twice (51 of 75 sampled issues on the duplicated instance) |
| B36 | verify read the `[open-parent]` note, which the push clears once the issue exists; verify's own clients had no retry | OAC 2 count failures after a full run; one verify run stopped on a dropped connection | **fixed**: open parents from the mapping (proposed status differs from the mapped Jira status), clients from `ClientFactory` |
| B37 | A select option Jira no longer offers stays on old issues (WIKI: one option on 294 issues; the list has a renamed one) | the Redmine field had only the offered values; 10 held all 294 issues back as MANUAL | **fixed** (option a, decided overnight, confirmed by Jan 2026-10-14): 08 adds used values to the field (`UsedValues`) and lists them (`[unoffered]`), taken in the usage phase once 10 staged the issues; 10 reads the options from 08's Redmine snapshot, so a value added to an existing field by hand is used too |
| B38 | A Jira parent in another project (GEOS issues under WIKI epics) | 422 "Parent task is invalid": Redmine allows subtasks within a project tree by default | Redmine setting "cross-project subtasks: with all projects" (option a, open for Jan); test runs set it |
| B39 | Jira links across projects | 29 relations 422 "doesn't belong to the same project" | Redmine setting "allow cross-project issue relations" (with B38, open for Jan); test runs set it |
| B40 | A Jira link between an issue and its own parent or subtask | 6 relations 422 | **fixed**: IGNORED with a note (the hierarchy holds it) |
| B41 | 11 rewrote 10's issue descriptions in staging after 10 pushed them | avatars stayed in Redmine (3 issues); issues not created yet got a broken hash and stayed "manual overrides" (17) | **fixed**: 10 cleans descriptions before its hash and push, 11 no longer touches them |
| B42 | Link markup Jira showed as text, URLs with parentheses | `](` visible (22 items) | **fixed** in descriptions and comments, PHPUnit cases from anonymised input |
| B43 | 08's purge of mappings without a Jira field deleted the synthetic `cascading_parent:` rows on every transform; after a rerun of 08 nothing recreated them | 10 lost the parent field of 3 cascading fields; issues created after the rerun lost their parent value (41 verify failures in the healed WIKI run) | **fixed** (`MappingKeys`) |
| B44 | The spacing clean-up joined digits after a reference; two clean-ups turned "user 5" into a mention | a mention followed by a number pointed to another user ("user#332 4 lines" became "user#3324": 8 journals in the SURV run, 7 comments in the data); "#12 3" would become "#123" | **fixed**, PHPUnit cases from anonymised input |
| B45 | 08 took the values of app fields without allowed values (label manager fields) only in the jira phase, before 10 staged the issues | 5 fields MANUAL, not migrated | **fixed** with B37 (usage phase): 34 fields created instead of 30. "Organizations" and "Request participants" (service desk) are now seen and MANUAL; not migrated before either |
| B46 | With 04's memberships from Jira's model (B31 a), Redmine refused issues whose assignee no membership covers | "Assignee is invalid": 10 issues in GEOS, SIS, OAC (5.1), 47 in the WIKI batch (7.0); Jira keeps an assignee who lost the permission | **fixed** (option a, decided overnight): 04 proposes such an assignee as a direct member (Developer) in the review table; 04 runs after the issues are staged. Every refused (project, assignee) is in the proposal: 6 of 6 (5.1), 9 of 9 (7.0) |
| B47 | A later batch: a select value its issues hold never reached a field created by an earlier batch | 294 WIKI issues MANUAL ("could not be normalised"); 08's push updated an existing field's projects and trackers, not its values | **fixed**: 08's push adds the missing values to a field the migration created (Extended API) and refreshes the snapshot 10 reads; a field that existed before is listed, not changed |
| B48 | A Jira user page cut off mid-transfer (cURL 18) | 02's extraction stopped in the WIKI batch; the retry covered failed connections only | **fixed**: every transport failure of a GET is retried |
| B49 | Heading anchors (`<a name>`) left as HTML in the Markdown | the link passes wrapped the whole text in `<p>`: Redmine showed `###`, `**`, `&gt;` (13 of 2,214 texts) | **fixed**: anchors dropped before conversion; 3 texts left (literal MediaWiki HTML, another class) |
| B50 | With 04's memberships from Jira's model (B31 a), Redmine drops watchers who are not members of the project | 48 FAILED rows ("204 but not stored") in the 5.1 run, 372 in the WIKI batch | **fixed** (Jan's decision 11, option b, `24d3611`): 04 proposes them as watcher-only members, 14 adds them, verify expects them present; `[no-access]` stays as the note of a row whose membership was not pushed. Before (option a, overnight): not migrated, 38 `[no-access]` rows in the final 5.1 run |
| B51 | 08's snapshot update used CAST(... AS JSON), which MariaDB refuses | B47's fix added the value in Redmine but 10 read the stale snapshot: the 294 WIKI issues stayed MANUAL in the proof batch | **fixed**: JSON_EXTRACT(:e, '$'); 08 compares with the field's values in Redmine before adding |
| B52 | A link to an issue that a later batch migrates stays a Jira link | 10 rewrites the links of created issues in staging but pushes only the issues still waiting; 11 pushes a comment once. In the WIKI batch: 1 SIS comment to a WIKI issue | **built** (decided overnight): opt-in `tools/relink_issue_links.php` after the last batch, guarded by the hash of the pushed text (10 and 11 store it). **Jan, 2026-10-14 (decision 16): batches plus relink** for production; the tool's check counts the texts still linking a migrated issue (must be 0; 7.0: 13 relinked, then 0) |
| B53 | A field created by an earlier batch was never enabled for a later batch's projects and trackers | 48 empty custom field values in the WIKI batch's verify (Rank, Epic Link, Solution, Time in Status): Redmine dropped them silently | **fixed**: 08's update plan takes the scope the Jira data gives now (FieldScope) against the field as Redmine has it |
| B54 | A link with an old issue key stays a Jira link: a renamed project ("DATA-12" for GEOS-12; GEOS was once DATA) or an issue moved to another project (new number) | the rewrite knew the current keys only; 3 GEOS comments | **fixed** (decided overnight, coordinator): 10 and 11 ask Jira once per key in a link that is no staged issue (GET issue/{key}?fields=key) and keep the answer in staging_jira_issue_key_aliases; every rewrite (10, 11, relink) uses one map with the old keys |
| B55 | verify expected a relation for a link between an issue and its own parent or subtask, which 12 leaves to the hierarchy (B40) | 1 WIKI failure (link 11351: a subtask's "To Wiki" link to its parent) | **fixed** in verify (`e0fe77b`), with a test |
| B56 | verify counted the address of link markup a user typed ("[label\|url]") as Jira text; B42 makes it a link with that label | 1 WIKI failure (comment 29072, 49% of the words) | **fixed** in verify and the bench (`b71306f`), with a test; the comment measures 100% |
| B57 | 05 reads the statuses from GET /rest/api/3/status, which does not list the 25 statuses Jira's status search (GET /rest/api/3/statuses/search) adds; 9 of them are in FPS's workflow (Accepted, Rejected, Re-Open, ...), no other migrated project uses one | FPS's workflow transitions to or from them are not proposed (reported as "no Redmine status"); FPS has 0 issues the read token can see, so nothing is lost now | **fixed** (coordinator: a completeness bug, fix regardless of FPS): 05 also reads the status search (GET, paged) and adds a status when a staged project uses it (GET statuses/{id}/projectUsages), with tests; on 5.1 and 7.0: 9 added, 16 used by none skipped, 8 created and 1 matched (Rejected); the proposal's "no Redmine status" problems 29 to 0, FPS 288 rows proposed and 16 to review (8 of them the group rows to Accepted and Rejected), the other projects unchanged (2,185 and 439) |
| B58 | 09 stored an attachment's Jira creation time without its offset ("2024-07-04T10:31:35.833+0200" became 10:31:35) and pushed it as UTC | every attachment one or two hours late in Redmine (issues and comments were right: 10 and 11 convert to UTC); verify had no check for it | **fixed** (`004b57b`, found by the M1 review of the timestamp helpers): staging holds the instant in UTC, a value without an offset is read as UTC; verify's new "attachments: created" fails 149 of 149 on the old data |
| B59 | 11 listed under a journal every attachment uploaded within 5 minutes of it; with B58's late times that matched 5 comments of the 5.1 test run (files uploaded an hour or two before them) | with B58 fixed (recorded run `b2-utc`): 228 status changes got a note of the files uploaded around them (so 249 more journals) and 199 comments extra lines; verify "journals: no duplicates" failed 6 | **fixed** (`e2ca087`): a changelog entry lists only the attachments its items name; for comments the window is the setting `migration.journals.comment_attachment_window`, 0 by default (Open questions) |
| B60 | The ADF path wrote a table with merged cells (rowspan, colspan) as a header narrower than its delimiter | no table in Redmine, the rows as text with "\|---" (GEOS-10, "the lost table row") | **fixed** (decision 26, `209f906`): the vendored converter spreads merged cells over the grid |
| B61 | Bold and italic that Jira splits or nests ("<em>16</em><sup><em>th</em></sup>", "<em><b>x</em></b>", emphasis split by a link) were written element by element; the delimiters touched | Redmine showed the asterisks (GEOS-53, GEOS-103, GEOS-36, WIKI-1373, WIKI-1040) | **fixed** (`ad0c83c`; around a link `fa5079c`): `Jira2Redmine\Text\Emphasis` regroups a run of inline text by its emphasis and leaves out a delimiter CommonMark would not read; 3 and 17 texts change, none with more visible asterisks or fewer links on Redmine 5.1 |
| B62 | The HTML converter (league/html-to-markdown) decodes a code block's text and then removes "<pre>" from it, also the text "<pre>" a user typed | MediaWiki source in a code block lost its `<pre>` lines (WIKI-843) | **fixed** (`df00c95`): that text passes the converter under another name |
| B63 | The ADF path wrote a code block between single backticks | inline code with its lines run together (31 of 33 ADF items; the pipeline uses the ADF path for ADF-macro texts) | **fixed** (`7e2c053`): fenced, longer fence when the code holds backticks |
| B64 | MediaWiki source pasted into Jira (template documentation) is rendered by Jira as a broken table | the broken table in Redmine too (WIKI-280, -282, -294, -295) | **fixed** (decision 26, `a18cc18`): the exact source from the ADF as a code block in its place (only a table "{\| ... \|}" or a block tag over several lines; anything less certain stays as Jira shows it) |
| B65 | Inline code that holds braces itself is shown by Jira as "{{...}}" | the braces as text (21 code spans in 7 texts: WIKI template code, GEOS-105) | **fixed** (`6560e6c`): where Jira's HTML shows "{{" + exactly the ADF's code + "}}", it becomes inline code |
| B66 | The Markdown link patterns of the text rewrites took the label lazily, from an earlier link's "[" to a later "](" | a profile link replaced everything from an earlier link on: whole sentences lost ("Linked to [SURV-977](...), second incident ... [Claire](profile)" became "Linked to user#66", WIKI-724; 15 texts) | **fixed** (`f194b7c`): a label is one line without brackets of its own |
| B67 | Link markup typed into Jira and shown as text ("to[ glossary\|url]", "[[1]\|url]") | as a link (B42) it lost the white space around it ("toglossary", 30 texts); with brackets in its label it stayed text and its address became a broken autolink (4 texts) | **fixed** (`d2fbce7`, `9cf258b`) |
| B68 | A mention right before a link | "Claire Pirotseismic logfiles" (WIKI-37) | **fixed** (`b0fe08c`): a space, as Jira shows them apart |
| B69 | An address left as plain text kept the converter's escapes ("document\\_control") | Redmine 5.1 and 7.0 link it with the backslashes: a broken link (WIKI-777) | **fixed** (`da60cf6`) |
| B70 | Jira's renderer writes a link inside a link for an address with ">" | nested Markdown links, "](" visible (WIKI-520) | **fixed** (`bebd09b`): the inner link gives up its address |
| B71 | The central retry (decision 5) covered 429 and 503, not a gateway error | the Atlassian gateway answered 504 to `GET issue/{id}/watchers` and 14's Jira phase stopped (7.0 WIKI batch, 2026-10-15; step 14 read the watchers again, nothing lost) | **fixed**: 502 and 504 are retried for GET and HEAD only (a write may have reached the server behind the gateway), with a test |
| B72 | A link to a migrated issue whose label ended with a space or inside a word became "text (#461)which" or "comment f (#1015)rom" | Redmine links "#123" only when no letter follows: shown as text (WIKI-42, -594, -1291 and 4 more) | **fixed** (decision 26, `eb045e6`): a link that ends inside a word takes the rest of the word into its text, otherwise a space follows the reference |
| B73 | 06 reads each Redmine priority's position from the REST API, which has none (`enumerations/index.api.rsb` in 5.1 and 7.0), so it proposes positions 1, 2, 3 for the new priorities | Redmine inserts them below the existing ones (position 1 is the lowest): on the 7.0 test instance "Blocker" became the lowest priority, "Medium" below "Low" (found writing docs/PRODUCTION.md, 2026-10-15) | **fixed** (`2a7c230`): a new priority is created without a position, Redmine puts it after the existing ones; proof on 5.1 and 7.0, position 9 after the eight existing ones. Since decision 35 06 creates none by default |
| B74 | 07 marks a tracker it matched to an existing one CREATION_SUCCESS once its push added it to the projects, like a tracker it created; decision 34's first build (`6623bd4`) read CREATION_SUCCESS as "created by 07" | proof on a Redmine that was not empty (5.1 and 7.0): the existing tracker "Task" got the union as its global workflow, 4 rows became 163; Redmine's default trackers Bug and Support were matched the same way in every earlier test run | **fixed** (`690e28c`): 07 sets `migration_mapping_trackers.created_by_migration` where it creates a tracker, the plan reads that; an older staging database needs the column |
| B75 | 13 sent the tags of an issue on 7.0 as one comma-joined string (`add_tag_list: "a,b,c"`); redmine_tags' 7.0 branch takes a string as one tag | on 7.0 every issue with several Jira labels got one tag named "a,b,c" instead of separate tags (17 of the 46 tagged issues of GEOS, SIS and OAC, every 7.0 run since B19; verify does not compare tags); found by the trace inventory (Jan, 2026-10-15) | **fixed** (`5dffd71`): the tags go as a list; proof on 7.0: two issues untagged and tagged again by 13 have their tags apart (#16 EM712, FAU, MBES) |
| B76 | `tools/relink_issue_links.php` rewrote with `replaceIssueLinksInText`, whose last step tidies the spacing around every reference ("#203," becomes "#203 ,"); 10's own rewrite of a description does not | a description 10 had already relinked was reported as one to relink, and the check counted it as still holding a Jira link: in a single run of GEOS, SIS and OAC on 5.1 the preview said 1 (SIS-112, whose only link 10 had rewritten), reproduced in a run of SIS alone with every Redmine request recorded (10's final update sent "#103,"; nothing wrote the text after it); with `--confirm-push` the relink only added the space. Found by the one-run check of decision 39 | **fixed**: the relink detects and rewrites the links only (`replaceIssueLinksOnly`, refactor `4031877`: the same output as before over 2,279 staged texts); the same SIS instance: 0 to relink, 0 left |
| B77 | The label app field "Offshore Vessel" (label-manager-for-jira) stores some selections of several labels as one comma-joined entry, its colours joined the same way | 08 made a joined entry one value of the Redmine field ("Geo_Ocean_IV, Geo_Ocean_VII"), or none above Redmine's 60 characters, and 10 held 4 SURV issues back as MANUAL (six vessels in one entry); found by the 14-project run of decision 39 | **fixed** (`7f68c18`): 08 and 10 split every entry of a label list on commas (`LabelManagerLabels`); on the 7.0 instance the 4 issues were then created with their six vessels as separate values |
| B78 | The Jira field "Agent Sessions" (`customfield_10525`, type agent-sessions: Jira's AI agent sessions, with session ids, states and the agent's account) is mapped by 08 to a list field | 10 holds back an issue with an agent session (SURV-1752, 29 journals with it); 08 tries to add the session's JSON as a value of the list | **open, for Jan**: B2's advice is not to migrate it, like decision 20 (`migration.custom_fields.not_migrated`) |
| B79 | 10 wrote a float field's value with `rtrim(rtrim($text, '0'), '.')`, which strips the zeros of a whole number | 0 became empty and 10 became "1": 18 revision numbers 0 and one 10 of DOC in the 14-project run; verify only checked that the field was not empty | **fixed** (`da4f2c9`): `DecimalText` writes the number as PHP does; verify compares a Jira number with Redmine's value (`custom fields: number`) |
| B80 | 15 posted each checklist item as it is; redmine_checklists limits an item's subject (512 characters on the 5.1 plugin, 1,000 on the 7.0 branch) | a longer Jira item was refused with 422 and 15 stopped the issue's checklist there, so the items after it were lost too (DOC-292 on 5.1: one item of 520 characters of the 977 staged); 7.0 took it | **fixed**: refused as too long, the item is posted again cut to the maximum Redmine names in its error (any language), ending in "…", and the mapping row says how many items were cut (`ChecklistItem`); production (7.0) cuts none of today's items |
| B81 | 11 logged a failed read of an issue's comments or history as `[warn] <message>`, without the issue's key, and its summary did not count it | a full disk during the decision 37 proof on 5.1 (a test script that downloaded all of SURV's files) left DOC-351 without two comments ("The table 'staging_jira_comments' is full"); 11 went on and the B7 log check (`[error]`, fatal, exception) did not see it; verify caught it only because DOC-351 was in its sample | **fixed**: such a read is an `[error]` with the issue key and "run 11 again" (a 401, 403 or 404 stays a warning), the summary counts the issues not read, and PRODUCTION.md B7 step 12 counts the comment and history reads (FAILED: run 11 again, it reads only those) |
| B82 | The `workflows` step of `manual_steps.rb` appends what it opens to `tmp/j2r_added_workflows.json`, and `restore` removes every transition the list names; nothing checked that the list belongs to the database it is used on | on 5.1 a list left by an earlier test run without restore (the SIS reproduction of B76; the test reset drops the database, not the list) named the four transitions of the existing tracker "Task", and restore removed its global workflow (4 rows to 0: decision 34 broken by a stale list); in production the same follows a Redmine restored without its list (B10's pg_dump variant did not name it) | **fixed**: the step stops before adding anything when the list names a transition this Redmine lacks (a list of this Redmine always matches until restore); `reset.sh` deletes the list; B10 names the list in the rollback without VM snapshots. Checked on 5.1: a stale list stops the step with 0 transitions added; two passes then restore (the batch case) add 158,733 and 0 and remove 158,733 |
| B83 | 15's transform kept every row whose checklist had not changed (automation hash), a FAILED row too | a rerun of 15 never retried a failed checklist, against PRODUCTION.md B10 ("FAILED rows are judged again"): DOC-292 (B80) stayed FAILED with 6 of its 9 items after the fix of B80 | **fixed**: an unchanged row is kept unless its push failed; on 5.1 15 then pushed DOC-292 again, all 9 items, the long one cut to 512 characters, the issue's updated time unchanged, no journal |

**Gate rerun on 5.1 with 96fe96a (recursion fix 22e3eb8 + parent author fix f9bf1e7), 2026-10-13:**
no SystemStackError; script results equal to 132fff5 (issues 365 created, 48 failed, 5 manual, 2
update failed, 6 waiting; journals 939; relations 60/60; watchers 777; attachments 367). Parent fields:
373 created issues, 11 of them parents, author_id and created_on equal to Jira for all. The verify
step itself stopped halfway: the Jira token was revoked during the run (401 Unauthorized on every read
since about 18:40).

**Gate for redmine_extended_api on 5.1 (2026-10-13, first run): RED.** Jan merges `redmine70-migration` into
`main` once the 5.1 rerun is green; `main` was already at ae192d4 (code = 8bb01dc) when tested. Same
full run as run 2, 5.1 with ae192d4 instead of 132fff5: **0 of 426 issues created**, 422 times HTTP
500, verify FAIL (presence 0/75). Cause: `SystemStackError` between the Extended API's
`Issue.prepend` of `safe_attributes=` (issue_patch.rb:26, calls `super`) and `redmine_tags` `master`
(the 5.1 production branch), which chains the same method with `alias_method` (issue_patch.rb:33-34,
133); plugins load alphabetically, so the alias captures the prepended method and `super` loops. 7.0
is not affected (`redmine_tags` `redmine70-migration` prepends too). Reported to the coordinator for
session A; rerun the gate on the next commit.

## Results

**Redmine cores of B2's runs.** Every B2 run (from 2026-10-13 19:40 UTC, when B2's `setup.sh` cloned both
instances) used 5.1 `bcf917c` (5.1-stable-GEOxyz) and 7.0 `1d8c2a3` (7.0-stable-GEOxyz, the head after the
upstream rewrite from 8067e23/1bf0ef6). Checked 2026-10-14 10:50 UTC: both equal the remote branch heads, so
no 7.0 proof needs a rerun. `tools/testenv/core.sh` (called by `setup.sh`) now brings an existing checkout to
the branch head with a forced refspec and prints the hash, so an old core cannot stay in place unnoticed;
`--migrate` bundles, migrates and restarts an instance whose hash changed. Runs from now on name the core hash.

**Dress rehearsal, 2026-10-14 evening** (Jan's decisions 1 to 25, code `2853767`; cores 5.1 `bcf917c`, 7.0
`1d8c2a3`). Plugins first (`tools/testenv/plugins.sh --migrate`, then a fresh baseline dump, since run_all
restores the baseline): 7.0 43 of 44 plugins to their `redmine70-migration` heads (redmine_extended_api already
at `d103a2d`, redmine_wiki_extensions unchanged; redmine_project_workflows `18c02ad`); 5.1 the production set,
2 changed (redmine_project_workflows `18c02ad`, redmine_contacts_helpdesk `02de92c`). 5.1 ran on PHP 8.2.34, 7.0
on 8.3.6. `STABILITY=1 WORKFLOWS=1 RESTORE=1 run_all.sh 51 b2-dress`; 7.0 `STABILITY=1 run_all.sh 70 b2-dress`,
then the cumulative `BATCH=1 WORKFLOWS=1 RESTORE=1 J2R_PROJECTS=geos,sis,oac,wiki run_all.sh 70 b2-dress-wiki`.

| check | 5.1 | 7.0 full run | 7.0 WIKI batch |
|---|---|---|---|
| verify | PASS | PASS | PASS |
| second pass of 08 to 15 | unchanged | unchanged | (batch) |
| issues, journals, attachments, relations, watchers | 426, 1,488, 576, 71, 1,142 | 426, 1,534, 576, 71, 1,169 | 1,883, 8,643, 1,413, 885, 4,761 |
| workflows: apply, then a second run | +1,940 -143,860, then +0 -0; 0 to review, 32 ignored, 13 projects | (batch) | the same |
| restore: temporary role left, watchers kept, (b) edited and saved / refused | 0, 1,142, 12/12, 12/12 | (batch) | 0, 4,761, 101/101, 101/101 |
| memberships equal 04's proposal | yes | (batch) | yes |

Against the last runs (`51-b2-final7`, `70-b2-final7`, `70-b2-final7-wiki`, timings left out) the step results,
the counts per status and the verify tables are identical. The differences are the decisions of today: 159,871
opened workflow transitions instead of 112,767 (B57's statuses); 7 fewer member rows (6 in the WIKI batch): TEST
no longer created, the 4 G group memberships added; the workflow step now applies within the run (the earlier
runner stopped on a rails error then, the proofs were done by hand), so restore removes only the 14,914 opened
transitions the plan does not own (before: all 112,767).
Then 5.1 again with redmine_custom_workflows at production's commit `0398974` ("2.1.3 devel"; the instance had
loaded tag v2.1.2, not the 5.x of `plugins-5.1.tsv`, whose 5.x and master need Redmine 6): `b2-dress2`, the same
summary as `b2-dress`. That run, with every Redmine request recorded, was also the "before" of the M1 proof.

**7.0 on the new heads, night of 2026-10-14** (coordinator: 7.0-stable-GEOxyz rewritten to `ad17efd`, plugins
moved). `core.sh --migrate`: 7.0 `1d8c2a3` to `ad17efd` (rewritten branch, 111 files; Rails 8.1.4), 5.1 unchanged
`bcf917c`. `plugins.sh 70 --migrate`: 9 of 44 changed, redmine_project_workflows `0da1e64`, redmineup_tags
`2b1fcfa`, redmine_issue_templates `af87c8b`, redmine_mail_digest `18e94e4`, redmine_checklists `bf00557`,
custom_field_sql `2a752cf`, redmine_contacts_helpdesk `df27ec3` (vendor 4.4.0), redmine_ai_triage `23324fc`,
redmine_drawio `7ec9013`; plugin migrations ran for checklists (8), contacts_helpdesk (14, 15), mail_digest (12)
and tags (4, 5). Fresh baseline dump, then the dress rehearsal's two runs with code `040e799` (the dress
rehearsal's scripts plus the M1 refactor, before B58), PHP 8.3.6: `STABILITY=1 run_all.sh 70 b2-night` and the
cumulative `BATCH=1 WORKFLOWS=1 RESTORE=1 J2R_PROJECTS=geos,sis,oac,wiki run_all.sh 70 b2-night-wiki`. Both
**PASS**; both summaries are identical to `b2-dress` and `b2-dress-wiki` apart from the timings (every step
result, the counts per status, Redmine's counts, the verify tables, the workflow apply and its second run +0 -0,
the restore proof 0 roles left and 101/101, memberships equal 04's proposal); the warnings in the step logs are
the same in number. Steps took 1,731 s and 4,800 s (dress rehearsal 1,795 s and 4,835 s).

**M1 rest, behaviour changes proven, 5.1, night of 2026-10-14** (B58 `004b57b`, UTC `bfdcc13`, B59 `e2ca087`; PHP
8.2.34, core `bcf917c`, the plugins of the dress rehearsal; `RECORD= STABILITY=1 WORKFLOWS=1 RESTORE=1 run_all.sh 51
b2-b59` against `b2-m1`, the recorded run of `eb53e88`): 7,103 Redmine requests against 7,116, verify **PASS** with
the new check "attachments: created" 149/149, stability unchanged, the restore proof as before. Every difference,
aligned request by request (`difflib` over the normalised logs):
- 576 uploads (every attachment) differ only in `attachment[created_on]`: 374 two hours and 202 one hour earlier,
  Jira's offset in summer and winter (B58);
- 13 changelog entries no longer pushed (journals 1,277 to 1,264, skipped 2,937 to 2,950): notes made only of
  "> attachment:" lines for files uploaded an hour or two before the status change, which the window matched
  through B58's error; their items name no attachment (B59);
- 5 comments the same text without those lines (the same cause);
- watchers 1,142 to 1,140 in Redmine: two of the 13 entries were their author's only journal on the issue, and
  Redmine adds the author of a journal as watcher. Jira's watchers are pushed unchanged (960, the same requests).
Nothing else differs. In between, `b2-utc` (B58 and UTC without B59) showed why B59 is needed: 7,365 requests,
249 more journals, verify FAIL on 6 duplicate journals.
On 7.0 the same (`STABILITY=1 run_all.sh 70 b2-head`, code `975c5bf`, core `ad17efd`, PHP 8.3.6, against
`b2-night`): verify **PASS** with "attachments: created" 149/149, journals 1,277 to 1,264 (skipped 2,937 to
2,950), watchers in Redmine 1,169 to 1,167, stability unchanged; the rest of the summary is identical.

**SURV volume run, 5.1, B2, 2026-10-13** (`J2R_PROJECTS=surv J2R_TEST_SAMPLE=1 run_all.sh 51 b2-surv`,
code before B44): 1,494 issues, all created (10 in 479 s); attachments 6,323 in Jira, 4,306 in the sample
(0.98 GB, pull 518 s, push 182 s), 2,017 skipped by sample (97.7 GB); journals 8,674 pushed and 12,882
changelog entries without content skipped (11 in 2,592 s, Jira extraction included); relations 151
created, 309 to issues outside the run, 3 parent/subtask links ignored (B40); watchers 3,750. A second
pass of 11 to 15 (by accident, see below) created nothing twice: verify "journals: no duplicates"
25/25. Verify, 50 issues: 3 failures before the verify fixes, all explained: one mention joined with a
number (B44, fixed after this run), one empty rich text field and one checksum that matched on every
later read (verify fixes). By accident `run_all.sh` was edited while this run used it; bash then
repeated 13 and 14 and ran 11 to 15 once more.

**Phase 9: final runs, B2, 2026-10-14 — green on both targets.** Scripts at b556cc6, Extended API
`redmine70-migration` c806a17 on both (the version Jan merges into `main` once the gate is green; the
gate is green), GEOS, SIS and OAC, no attachment sample, fresh instances and staging
(`STABILITY=1 run_all.sh 51|70 b2-phase9`):

| | Redmine 5.1 | Redmine 7.0 |
|---|---|---|
| verify (25 issues per project, 22 checks) | **PASS, 0 failures** | **PASS, 0 failures** |
| presence / counts tracker+status | 75/75, 30/30 | 75/75, 30/30 |
| comments, journals without duplicates | 254, 75/75 | 254, 75/75 |
| custom fields with a value | 380 | 380 |
| attachments name+size / sha256 | 75 / 136 | 75 / 136 |
| relations, watchers | 35, 75 | 35, 75 |
| issues created | 422 (+4 waiting for a parent outside the run) | 422 (+4) |
| journals pushed / skipped (no content) | 1,277 / 2,937 | 1,277 / 2,937 |
| attachments | 576 | 576 |
| relations created / outside the run | 71 / 49 | 71 / 49 |
| watchers stored | 960 | 960 |
| custom fields created | 34 | 34 |
| project memberships from roles (04) | 38 | 38 |
| second pass of 08 to 15 | **nothing changed** | **nothing changed** |
| script time, structure to 16 | 1,278 s | 1,255 s |

Longest steps: 11 (journals, Jira extraction included) about 460 s, 14 (watchers, read back) about
185 s, 09 (pull 95 s, push 136 s for 1.5 GB), 10 about 110 s. Differences between the targets are
Redmine's own: 7.0 writes a journal for a tag change (1,535 journals and 258 details against 1,489 and
212) and adds 27 watchers itself (1,140 against 1,113).

What is not covered by these runs: the manual steps and decisions still open for Jan (memberships
B31, select values B37, cross-project settings B38/B39, automation rules, service desk fields), the
Apache configuration of the production servers (runbook appendix), and phase 6 rest (M1).

**Phase 9 again with the overnight code, B2, 2026-10-14 (night) — green on both targets.** Final runs at
5ef122b on both targets (`STABILITY=1 run_all.sh 51|70 b2-final4|b2-final3`; the earlier runs at
9e64fe7 and 632a000 gave the same numbers), Extended API c806a17, GEOS, SIS, OAC, fresh instances and
staging. Later commits change only the bench, verify's measure and documentation.
New since the run above: memberships from Jira's model (B31 a) with assignee-only members (B46) and
04 after the issue extraction, watchers without project access (B50), the conversion fixes, retry on
cut transfers (B48), the B37/B38 lists, 16's workflow scheme per project.

| | Redmine 5.1 | Redmine 7.0 |
|---|---|---|
| verify (25 issues per project, 23 checks) | **PASS, 0 failures** | **PASS, 0 failures** |
| issues created | 422 (+4 waiting: parents in GSPP 1, WIKI 3) | 422 (+4) |
| journals pushed / skipped | 1,277 / 2,937 | 1,277 / 2,937 |
| attachments, relations created | 576, 71 | 576, 71 |
| memberships pushed by 04 (created / role added / present / failed) | 79 / 7 / 10 / 0 | 79 / 7 / 10 / 0 |
| of which assignee-only members (B46) | 7 | 7 |
| watchers stored / without project access (B50) | 922 / 38 | 922 / 38 |
| verify: watchers without access, expected absent | 7 of 7 | 7 of 7 |
| second pass of 08 to 15 | **nothing changed** | **nothing changed** |

Watchers stored fell from 960 to 922: the 38 are users without Browse projects in Jira (not in the
groups the permission schemes name), whom Redmine refuses as watchers; all 38 rows say `[no-access]`.

**Phase 9 at the then head `d692538`, both targets (2026-10-14, `STABILITY=1 run_all.sh 51|70 b2-final5`).**
**PASS** on 5.1 and 7.0, the second pass of 08 to 15 changed nothing, 4 issues waiting for a parent
(GSPP 1, WIKI 3). B54 in a full run: 8 issue keys in links asked of Jira, 3 old keys of GEOS issues
(DATA-n). Superseded by Jan's decisions 12 and 13 (later the same morning): their proof runs follow.

**Decisions 12 and 13 on 5.1, 2026-10-14** (`STABILITY=1 WORKFLOWS=1 RESTORE=1 run_all.sh 51 b2-final7`, code
`a813ffb`, then the workflow steps by hand with `a4a3d9c`): verify **PASS**, stability unchanged, watchers
without access 0 (the temporary role covers them; 1,142 watchers against 1,082 before). After restore:
temporary role gone (1,251 member roles), 417 users locked again, members 1,689 (04's 1,696 minus the 7
assignee-only members that are off now); (a) 1,142 watchers kept, 217 of them of users who are no member;
(b) 12 issues whose assignee is no member: 12 of 12 edited and saved, the same assignee refused on another
issue 12 of 12; (c) memberships equal 04's proposal. Workflows, mode project: 2,193 transitions over 172
project/tracker/role sets (14 projects) and 6 "assign to the current user" scripts applied, a second run
+0 -0; GEOS Task (Developer, from In Progress) offers 4 statuses, not "Create RMA", LABO Task offers it
(10); the workflows step (111,819 opened), the runner (99,557 of them replaced), restore (12,262 removed),
then the preview: +0 -0. 439 rows wait for Jan: transitions limited to a group that is not a member.

**Jira workflow rules and how the migration carries them** (16's export of 2026-10-14: 64 workflows, 26 used
by the migrated projects with 332 transitions, 38 unused with 524). Counts: transitions / workflows.

| rule | used | unused | how the migration carries it | not carried (used) |
|---|---:|---:|---|---:|
| transition (any) | 332 / 26 | 524 / 38 | Redmine workflow per tracker and role; per project with redmine_project_workflows | 0 |
| initial transition (create) | 26 / 26 | 38 / 38 | "(new issue)" transition per tracker and role | 0 |
| global transition (any status to X) | 11 / 4 | 16 / 5 | expanded to a transition from every other status | 0 |
| reflexive transition (X to X) | 0 | 0 | not needed: Redmine edits without a status change | 0 |
| condition: user in group | 196 / 20 | 347 / 26 | the Redmine roles the group holds in the project (04); a group that is not a member: review row for Jan | 67 |
| condition: permission | 6 / 2 | 20 / 5 | only the roles that hold the permission in the project (rule 4) | 0 |
| validator: permission | 20 / 20 | 34 / 34 | as the permission condition | 0 |
| condition: only assignee | 0 | 4 / 2 | Redmine's assignee transition | 0 |
| condition: field value | 1 / 1 | 0 | not carried (FPS "Screen" only with one option of a select field); possible as a custom workflow script | 1 |
| condition: only Bamboo notifications | 0 | 2 / 1 | not carried (a build server's transition) | 0 |
| condition: only reporter, user in project role, subtask blocking | 0 | 0 | (Redmine's author transition would carry only reporter) | 0 |
| validators other than permission (field required, date, regex, parent status) | 0 | 0 | | 0 |
| post function: assign to current user | 3 / 2 | 5 / 3 | redmine_custom_workflows script per tracker and status (6 scripts) | 0 |
| post function: update or clear field Resolution | 39 / 20 | 56 / 26 | nothing to do: Redmine has no resolution, the closed status is it | 0 |
| post function: fire standard event (generic, created, resolved, closed, reopened) | 331 / 26 | 518 / 38 | Redmine's own notifications | 0 |
| post function: fire event issue_updated | 1 / 1 | 6 / 3 | Redmine's own notification of an update | 0 |
| post functions assign to reporter/lead, copy value, create issue, other update field | 0 | 0 | | 0 |
| transition screen "Resolve Issue Screen" | 63 / 20 | 123 / 31 | field permissions for the fields it requires: none (all optional in all 12 field configurations); Redmine shows the fields on every edit | 0 |
| transition screen "Workflow Screen" | 0 | 6 / 3 | (unused) | 0 |
| transition labels (jira.i18n.title, jira.i18n.submit) | 18 / 4, 13 / 3 | 54 / 9, 27 / 6 | not carried: Redmine shows the status names, not transition names | 18 |
| button order (opsbar-sequence) | 0 | 14 / 2 | | 0 |
| status read-only (jira.issue.editable = false) | 0 | 3 statuses / 3 | (unused; would be read-only field permissions per status) | 0 |
| jira.permission.* properties | 0 | 0 | | 0 |
| DevOps triggers (commit.command.mapping, build.*) | 0 | 10 / 1 | not carried; Jira's REST API exports no triggers | 0 |

In short: redmine_project_workflows v0.1.6 with Redmine's workflow model carries every rule the used
workflows have, except a field value condition (1 transition, FPS), the transition labels (cosmetic) and
the transitions limited to a group that is not a member (67, Jan decides per case).

**Extended API version.** Jan rewrote redmine_extended_api's history (trailers removed): `main` =
`redmine70-migration` = `d103a2d`. The code equals the `c806a17` the runs above cite (only 7 documentation
files differ); the test instances' checkouts were reset to `d103a2d` on 2026-10-14 after the 7.0 batch.

**Decisions 12 and 13 on 7.0, 2026-10-14** (`STABILITY=1 run_all.sh 70 b2-final7`, then
`BATCH=1 RESTORE=1 J2R_PROJECTS=geos,sis,oac,wiki run_all.sh 70 b2-final7-wiki`, code `a813ffb`; the workflow
steps and proof (b) by hand with `45877d5`, plugin v0.1.6 `d951430`). Full run: verify **PASS**, stability
unchanged, 0 watchers without access. Cumulative WIKI batch: verify **PASS** (B55 and B56 gone), 1,882 of
1,883 issues created (1 waits for GSPP), relations 885 created, 85 to projects outside the run, 8 ignored
(B40), 0 watchers without access. Restore: 1,668 temporary member roles removed (417 users, 4 projects),
members 1,689; (a) 4,761 watchers before and after, 1,725 of them of users who are no member; (b) 101 issues
whose assignee is no member: 101 of 101 edited and saved, the same assignee refused on another issue 101 of
101; (c) memberships equal 04's proposal. Workflows, mode project: 115 scopes made through the plugin, 1,202
project rows; a second run +0 -0; the workflows step, the runner, restore (12,262 removed), then +0 -0;
GEOS Task (Developer, from In Progress) 4 statuses without "Create RMA", LABO Task 10 with it.

**Workflows against Jira, 5.1 and 7.0, 2026-10-14** (`tools/workflow_compare.php` with
`workflow_fingerprint.rb`, code `c822b66`, plugin v0.1.6). For every migrated project, tracker and role (04's
memberships) and every status, the statuses Redmine offers against what the project's Jira workflows allow that
role: 136 sets, 5,848 answers, the same on both targets.

| category | 5.1 | 7.0 |
|---|---:|---:|
| equal (a transition Jira allows the role, offered) | 1,202 | 1,202 |
| group condition: the group is not a member of the project (review rows for Jan) | 454 | 454 |
| group condition: the role is not one the group holds | 319 | 319 |
| permission condition: the role does not hold the permission (rule 4) | 9 | 9 |
| current status (Redmine lists it with the targets) | 539 | 539 |
| default status of a new issue (Redmine adds it when no creation transition applies) | 41 | 41 |
| **unexpected** | **0** | **0** |

The FPS field value condition sits on a transition that is also limited to a group that is not a member, so
it counts there. On the way the check found two faults, both fixed: a project role its own Jira workflow
gives nothing fell back to the global union (703 transitions Jira does not have, 18 sets; `0664ed2`, now 21
own empty workflows), and the fingerprint itself measured every status as the first on 7.0 (`update_column`
returns false there; `5903798`).

**Transitions limited to a group that is not a member: the options per case, 2026-10-14** (Jan followed the
recommendations, decision 14; for Jan's
decision on the 439 review rows / 454 answers above; Jira read with GET only, memberships from 7.0 after the
full run, which are 04's). Per project and Jira group: the Jira transitions (a transition limited to two
such groups counts for both), two or three examples, the group's users who are project members and their
roles, and the cost of each option. **R**: the transitions go to the roles those users hold; counted: project
members outside the group who gain them. **G**: the Redmine group becomes a project member with an own role
that has only these transitions; counted: group users who are no member today and would become one. **I**:
IGNORED; counted: Jira issues today in the from-statuses (those issue types), an upper bound of who gets
stuck, other transitions may still leave the status. Configuration and counts only.

| project | Jira group | transitions / trackers | examples (from > to) | group users who are members: roles | R | G | I | recommendation |
|---|---|---:|---|---|---:|---:|---:|---|
| DOC | DOC Support | 19 / 6 | New > In Progress; In Progress > Closed; Reopened > In Progress | 18 of 18: Manager 3, Developer 18 | 201 | 0 | 385 | **G** |
| FPS | GEOxyz- Crewing approvers | 2 / 2 | Ready for review > Accepted; Ready for review > Rejected | 7 of 7: Manager 2, Developer 6 | 5 | 0 | 0 | **G** |
| GEOS | Survey Support | 3 / 6 | To Wiki > Closed; Waiting Site Info > In Progress; In Progress > Waiting Site Info | 20 of 20: Manager 3, Developer 20 | 199 | 0 | 15 | **G** |
| GEOS | atlassian-addons-admin | 1 / 6 | To Wiki > Closed | no users | 0 | 0 | 0 | **I** |
| ICT | GEOxyz-Jira-User | 2 / 7 | Closed > Reopened | 7 of 219: Manager 6, Developer 4 | 0 | 212 | 2 | **R** |
| ICT | ICT Support | 18 / 7 | Waiting Site Info > In Progress; New > Assigned; Reopened > In Progress | 4 of 4: Manager 3, Developer 4 | 3 | 0 | 0 | **G** |
| LLE | GEOxyz-Jira-User | 1 / 3 | Closed > Reopened | 13 of 219: Manager 5, Developer 11 | 0 | 206 | 0 | **R** |
| SIS | GEOxyz-Jira-User | 1 / 1 | Closed > Reopened | 37 of 219: Manager 5, Developer 36 | 0 | 182 | 6 | **R** |
| SOIL | atlassian-addons-admin | 1 / 5 | To Wiki > Closed | no users | 0 | 0 | 1 | **I** |
| SURV | atlassian-addons-admin | 5 / 5 | To Wiki > Closed; Labo Task > Closed; Labo Defective Equipment > Closed | no users | 0 | 0 | 0 | **I** |
| TEST | GEOxyz-Jira-User | 2 / 4 | Closed > Reopened | 3 of 219: Manager 2, Developer 3 | 0 | 216 | 0 | **R** |
| TEST | Survey Support | 13 / 4 | Reopened > Closed; New > In Consideration; To Supplier > Closed | 1 of 20: Manager 1, Developer 1 | 2 | 19 | 0 | none clear: R (2 extra) or I, a test project |

- **G** is exact where the group's users are all members already (DOC, FPS, GEOS Survey Support, ICT
  Support: through GEOxyz-Jira-User, Datalab Support, GEOxyz-Crewing, ICT DEV or jira-admins-geoxyz): no
  new membership, nobody outside the group gains a transition. The own role must hold "Edit issues" (or "Add
  issues"): Redmine ignores the workflow of a role without either (`Role#consider_workflow?`, 5.1 and 7.0).
  For a user who is a member through another role that adds nothing beyond the transitions; a group user who
  is no member gets no issue without "View issues". Today the role and the group membership are manual
  (create the role, add the group to the project, set `review_role_id` to the role and PROPOSED); the runner
  then gives that role its own project workflow like any other.
- **R** is exact for GEOxyz-Jira-User (all 219 licensed users): in ICT, LLE, SIS and TEST every project
  member is in it, so nobody gains what Jira does not give; G would add 182 to 216 members.
- **I** for atlassian-addons-admin: the group has no users, the transitions are for Jira apps
  (automation); Redmine has no such caller. An admin gets only the union of the roles' transitions, so
  SOIL's 1 issue in "To Wiki" needs another transition out of it or a manual close.
- FPS's 2 transitions were no review rows: Accepted and Rejected were missing from staging (B57, now fixed:
  8 review rows for the group). FPS has 0 issues the read token can see.

**Jan's group settings built (decision 14), 5.1 and 7.0, 2026-10-14** (`877b363`). The proposal gives a
transition limited to a group to the roles 04's membership rows give that group (`groupRoles`), so G needs no
change in the proposal or the runner: 04 proposes the group as a member (pseudo Jira role -6, named after the
group's own role `<group> (workflow)`, found by that name) and its push creates the role through the Extended
API when Redmine lacks it (`edit_issues` only, not assignable, no view_issues; Redmine applies a role's workflow
only with edit or add issues). R gives the transitions to the roles the group's users hold through 04's
memberships; ignore makes the review row IGNORED; a group not listed stays a review row. One class
(`GroupConditions`) decides it for the proposal and the comparison with Jira; the extraction was a separate
refactor first (`6689dd6`: review table of 2,928 rows with their hashes, report and comparison identical).
A listed group that is no Jira group, or a project 01 did not stage, is printed as `[warn]` and gets no row.

| check | 5.1 | 7.0 |
|---|---|---|
| 04: roles created, group memberships pushed | 4, 4 (role #7 to #10) | 4, 4 (role #7 to #10) |
| 04 second run | nothing to push | nothing to push |
| memberships equal 04's proposal (`membership_check.php`) | yes, 83 (79 + 4) | yes, 83 (79 + 4) |
| transition rows: proposed / review / ignored | 3,174 / 12 / 32 (was 2,473 / 455 / 0) | 3,174 / 12 / 32 |
| rows still in review | 12, all the FPS field value condition | the same |
| runner: apply, then a second run | +981 -0, then +0 -0 | +981 -0, then +0 -0 |
| DOC Sub-task "New > In Progress", New Document "New > Assigned" (`group_role_proof.rb`) | DOC Support user yes, Developer/Manager outside the group no: PASS | PASS |
| `workflow_compare.php` (157 sets, 8,007 answers) | 0 unexpected; equal 1,723; "group not a member" 0 | the same |

Remaining comparison categories on both: the role is not one the group holds 780, current status 785, default
status 51, permission condition 9, the FPS field value condition 6 (not carried, review).

**Plugin upgrade rehearsal on 5.1, 2026-10-14** (redmine_project_workflows v0.0.3 `d61b1de` to v0.1.6
`f2c1c2f`, on the state the runner wrote with the old version). Migrations 004 to 007 ran; 004 made 115
scopes from the 115 project sets, rows unchanged (1,092 global, 1,202 project). `workflow_fingerprint.rb`
(for every project set, tracker, role and status the statuses Redmine offers): 4,945 answers, identical
before and after; GEOS Task still without "Create RMA", LABO Task with it; the runner's preview +0 -0.
Migration 006 printed "another plugin still registers manage_project_workflow" (passed to session C).
Session C's runner remarks: (b) reproduced (a scope removed, its 38 rows left without one: the old runner
needed two runs) and fixed (`e0afb7f`, one run); (a) unknown ids now stop the run.

**Workflow proposal (5.1 staging of this run, `tools/workflow_proposal.php`).** 26 Jira workflows over
74 project and issue type pairs (all 15 projects 01 created): 827 rows proposed, 176 to review. Rules
Redmine cannot apply, by rows: transition screens 96, a permission condition given to every role of
the project 68 (CREATE_ISSUES 48, RESOLVE_ISSUES 18, ASSIGNABLE_USER 2), the post function "assign to
the current user" 12. Not proposed: transitions from or to 9 Jira statuses without a Redmine status
(not in Jira's status list that 05 reads, and no staged issue has them), transitions limited to groups that are not members of the project
(TEST, ICT, SURV, GEOS, DOC). Eleven trackers are fed by 2 to 14 Jira workflows (corrected 2026-10-14; this said five): Redmine has one
workflow per tracker, so the proposal is their union. A hand edit (IGNORED) stayed on a rerun. Nothing is sent
to Redmine: how to apply it is Jan's decision (open question).

**Cumulative WIKI batch on 7.0 with the final code** (`STABILITY=1 run_all.sh 70 b2-final3`, then
`BATCH=1 J2R_PROJECTS=geos,sis,oac,wiki run_all.sh 70 b2-final3-wiki`, code 5ef122b): 1,883 issues, 1,882
created, 1 waiting for its parent in GSPP (B38: 92 waiting before the parents arrived, now 1); 0 issues
refused for their assignee (B46: 19 assignee-only members); the 294 issues with a value Jira no longer
offers created (B47, B51); the 7 fields of the first batch enabled for WIKI (B53). Verify (100 issues,
23 checks): custom fields 440/440 (48 failures before B53), counts 4/4 and 83/83, presence 100/100;
2 failures left, both in verify's measure, not in the migration: link 11351 is a subtask's link to its
own parent, left to the hierarchy by design (B55), and comment 29072 holds typed link markup that B42
makes a real link (B56). Both fixed in verify with a test.

**Relations to a later batch, 7.0.** The first batch (GEOS, SIS, OAC) leaves 49 links MANUAL ("Missing
Redmine issue mapping"): the other side is in WIKI for 30, in SURV 13, DOC 5, LABO 1. All 30 WIKI ones
were created by 12 in the cumulative WIKI batch (their link ids are among its `[created]` lines, the
first after the 71 of batch 1); the 19 others wait for their project. 12 re-evaluates a MANUAL row on
every run while nobody edited it, so a rerun after the later batch creates them; no extra step, unlike
links in text (B52).

**Relink after the last batch (B52) and old keys (B54), 5.1.** A GEOS run with the final code stored the
pushed-text hash of all 97 descriptions and 547 comments; a preview found none "edited", so the hashes
match what Redmine keeps. B54: 9 unknown keys in links asked of Jira, 3 are old keys of GEOS issues
(DATA-n). The relink preview found those 3 comments; one was then edited in Redmine; `--confirm-push`
rewrote the other 2 (Redmine now shows `#id`, no Jira link) and left the edited one alone; a second
preview found nothing left.

**Relink check, 7.0, 2026-10-14** (Jan, decision 16: batches plus relink; `06fd323`). On the cumulative WIKI batch
state, the preview found 13 texts with a Jira link to an issue migrated by a later batch (12 descriptions, 1
comment), 0 edited; `--confirm-push` rewrote the 13; the check afterwards: 0 relinked, 289 without a link to
rewrite, "Texts that still hold a Jira link to a migrated issue after this run: 0".

**Non member group (application-role grants), 5.1 and 7.0.** The final run proposed GSPP's and ROV's grants to
every licensed user as 4 rows for "Non member users", MANUAL_INTERVENTION_REQUIRED. An anonymised grant
on OAC, set READY as Jan would, was pushed (membership "Non member users", Developer); a logged-in
non-member, non-admin user then has the role's rights and is not assignable, as the note says. The
test memberships were removed again. Same on 7.0 (membership created through the push, user not
assignable, removed).

**Decisions 33 to 35 on a Redmine that is not empty, 5.1 and 7.0, B2, 2026-10-15** (code `690e28c`; runs
`b2-d33`, GEOS, SIS, OAC from the baseline, `WORKFLOWS=1 RESTORE=1`). Before the run
`tools/testenv/existing_redmine_proof.rb setup` gave each Redmine what an existing one has: a tracker "Task" (Jira
has an issue type of that name) with its own global workflow (4 rows), used by a project the migration does not
touch. After the run (`check`), on both targets:
- decision 34: Task's global workflow 4 rows, the same fingerprint as before; the migrated projects have their own
  workflow for Task (GEOS 19, SIS 15, OAC 22 rows, and the projects 01 created for later batches); `workflow_plan.php`:
  "Global workflow left as it is (decision 34) for 2 tracker(s) Redmine already had: 3, 4" (Support and Task; plan
  2,842 transitions over 220 sets instead of 3,080 over 228); runner +1,904 -134,062, then +0 -0, and +0 -0 after
  restore; restore removed 24,867 opened transitions (14,914 before: the open transitions of the existing trackers
  now go back too). The first run of this proof failed (`b2-d33-fail1`: 4 rows became 163, B74, fixed in `690e28c`).
- decision 35: 06 MATCH_FOUND 5 of 5 through the test mapping, nothing to create; Redmine's priorities Low to
  Immediate at positions 1 to 5 before and after.
- decision 33: the workflows step opened the transitions for the configured default roles, `roles=["Developer",
  "Manager"]`, 159,867 transitions.
- unchanged: verify **PASS**; 423 issues created, 4 waiting for a parent in a later batch; restore locked the 417
  activated users again; watchers kept (1,145 on 5.1, 1,172 on 7.0); memberships equal 04's proposal; the existing
  project has 0 issues and still only its tracker.

**No mail, no webhook from 13 and the relink, 7.0, B2, 2026-10-15** (Jan's decision; code `2df782a`; on the state of
the 7.0 run above). Mail to files (`delivery_method: :file`), a webhook for issue.created and issue.updated in the
migrated projects to a local receiver, webhooks switched on, and a user who wants every mail, active, Developer in
the projects and watcher of the 14 issues of the proof (`tools/testenv/silent_writes_proof.rb`). The relink had texts
to rewrite through 14 keys of projects outside the run mapped onto an existing issue, as a later batch would.
- control, a plain `PUT /issues/:id.json` with a note: 1 mail, 2 webhook calls (issue.updated for the issue and its
  parent): the counting works;
- 13 with the Extended API, 3 issues untagged first and tagged again (7.0: the issue update): **+0 mails, +0 webhook
  calls**; each tag change is a new journal (detail `tag_list`) by the migration account at the time of the run;
- the relink, `--confirm-push`: 9 texts rewritten (4 descriptions, 5 comments), **+0 mails, +0 webhook calls**; a
  rewritten description is a new journal (detail `description`) by the migration account at the time of the run, a
  rewritten comment keeps its author and date and gets "updated by" the migration account; the check afterwards: 0.
The first two attempts of this proof did not count (`70-b2-silent-fail1`: Redmine 7 refuses a loopback webhook URL
and the proof user was never made, so the control showed 0 too; `70-b2-silent-run2`: valid, but the relink had one
comment and no description). The webhook, the proof user and the fake mapping rows were removed afterwards.

**Traces of post-processing, B2, 2026-10-15** (Jan: "an issue in Redmine and in Jira as identical as possible, also in
dates, journals and authors"; the target: technical post-processing leaves no visible trace). Measured per run of
GEOS, SIS and OAC on a Redmine that is not empty (`b2-d33`, 427 issues), every journal and updated time the run
leaves after an issue is created; "before" is the code of `690e28c`, "after" `d56c2b3` (5.1) and `9f044e3` (7.0):

| write after creation | trace before (5.1 / 7.0) | after | what is left, and how it could go |
|---|---|---|---|
| 10, second pass (a description with links to issues created later in the run: 5; a parent created after its child: 32) | 37 journals by the author at the issue's creation, each marked "Edited" (updated time = the run) | 0 marked "Edited" (`560657c`) | the 37 journals themselves ("Description updated", "Parent task set"), by the author at creation; a journal-free update (E1 below) |
| Redmine core: a parent gets a "Subtask added" journal when a child gets its parent | 32 by the migration account, at the run | the same | E1 |
| Redmine core: a parent's dates and done ratio follow its subtasks (Settings, Issue tracking, parent tasks' attributes "derived") | 3 parents with a new updated time | the same | option S below; Jira does not derive them, so this also changes those parents' dates |
| 12, relations | 142 journals (both sides of 71 relations) by the migration account, at the run | the same | E1, or R2 below |
| 13, tags | 5.1: none (tags route); 7.0: 46 journals, 45 issues with a new updated time | 10 sends the tags with the create (`f39fa35`): on 7.0 46 → 17 journals, the 17 were B75; with its fix (`5dffd71`) a 7.0 rerun: **0 tag journals**, 46 issues tagged, tags apart (one tag "JIRA," is a Jira label as written, GEOS-58); on an existing issue (a rerun) 13's update keeps the updated time (`d56c2b3`), the journal stays | E1 for that journal |
| relink, descriptions | a journal by the migration account at the run, a new updated time | updated time kept (`d56c2b3`; proof on 7.0: the 11 issues of the relink all the same before and after), journal stays | E1; or no relink (one run, below) |
| relink, comments | the comment marked "Edited", "updated by" the migration account | the same | E2; or no relink (one run) |
| 14 watchers, 09 attachments (attached at creation), 10 open-parent "Resolved" (set at creation), 11 journals (authors and dates from Jira) | none | none | 92 comments edited in Jira keep their own "Edited" (faithful) |
| 15 checklists | none in these projects; created through the API without a journal; with redmine_checklists' "issue done ratio" on, the issue is saved (journal, updated time) | the same | the setting off for the run (PRODUCTION.md B5) |

Found on the way: **B75** (13's tags on 7.0 were one comma-joined tag), fixed in `5dffd71`.

Options for Jan (they need a change in redmine_extended_api, which has its own session and plan, or a Redmine setting).
**Jan: E1 and E2, not R2 (decision 37); S not taken (decision 38).**
- **E1, journal-free writes in the Extended API** (B2's advice). An admin-only request flag on `/extended_api`, next to
  `notify=false`, for example `journal=false`. Under it: `Issue#create_journal` (the after_save that saves the
  issue's current journal; 5.1 `issue.rb:2029`, 7.0 `:2059`) saves nothing; `Issue#create_parent_issue_journal` (the
  after_commit that writes "Subtask added" on the parent; 5.1 `:2035`, 7.0 `:2065`) writes nothing; on
  `IssueRelationsController#create` and `#destroy`, `IssueRelation#init_journals` (5.1 and 7.0 `issue_relation.rb:207`)
  starts no journal on either issue. With the existing issue override `updated_on` the issue's time stays. Tests:
  per case a request spec that the journal count and the updated time do not change, that the flag does nothing for a
  non-admin, and that notify=false still works. The scripts would then send the flag on 10's second pass, 12, 13's
  update and the relink: no trace left by any of them.
- **E2, a comment edit that keeps its times**: `JournalsController#update` through `/extended_api` with the flag keeps
  the journal's `updated_on` and `updated_by_id` (restored after the save). Only the relink needs it.
- **R2, instead of E1 for relations**: date each relation journal by the Jira history entry of the link (its author and
  time); the journal overrides already reach `init_journal`, but the relations patch would have to accept them, and 11
  or 12 would have to read the link's history. More faithful only if Jan wants the link history kept; more work.
- **S, parents' dates and done ratio independent** (Administration, Settings, Issue tracking), as decided for the
  priority (B13): Jira does not derive them, so the migrated parents keep Jira's dates and no parent is saved again
  (3 per run). It is a global setting: existing GEOxyz projects whose parents follow their subtasks today would stop
  doing so. Jan's choice.

**One run instead of four batches** (decision 36 makes it possible; it changes decisions 16 and 22, so Jan decides;
**Jan: one run, decision 39**; docs/PRODUCTION.md B7 to B11):
- the relink becomes (nearly) unnecessary: in one run every issue exists before 11 pushes the comments and before 12
  creates the relations; 10's second pass rewrites the descriptions that link issues created later in the same run.
  What is left are links to issues outside the migration (TEST), which stay Jira links anyway. The relink's check
  stays as the proof (it must say 0).
- B38 waiting parents and relations to a later batch disappear (only parents and links outside the migration remain).
- duration: about the same work, a little less (14's jira phase, 16 and 08's transform read everything once instead of
  four times: roughly 30 to 60 minutes less); one long run of about 7 to 12 hours in production, in tmux.
- Jan's reviews: the same review points once, larger (all memberships of 14 projects at once); B6's matching review
  is the same, since 01 to 07 already cover all projects in the first batch.
- rollback: no batch boundaries, but natural pauses for a VM snapshot: after the structure and its reviews (01 to 08),
  after 09 and 10, after 11 to 15. Inside the window a rollback costs no user work either way; the scripts can rerun
  any step, so a failure is usually a rerun.
- disk: all attachments at once (about 116 GiB in `paths.tmp` and again in `files/`), or empty `paths.tmp` after 09's
  push (the local copies are not read after the upload).
- B2's recommendation: **one run of all 14 projects in the window**, with the snapshots at those three pauses, the
  relink kept only as the check, and batches kept as the fallback in the runbook. The rehearsal runs it that way.
After all of today's changes (7.0 rerun `b2-d33`, code `9f044e3`; 5.1 run on `d56c2b3`, whose code differs only in
13's 7.0 path): verify **PASS** on both; journals the run writes after creation: 0 tag journals, 0 marked "Edited", 142
relation journals and 32 "Subtask added" journals left (E1); issues with an updated time in the run: 4 on both, the 3
parents of option S and 1 issue Jira itself updated that day.

**The one run of the 14 projects (decision 39), B2, 2026-10-15/16.** Test instance 7.0 on a Redmine that is not empty
(`existing_redmine_proof.rb`: a tracker "Task" with its own global workflow, an existing project), the procedure of
docs/PRODUCTION.md B7 and B9 through `run_all.sh` (`EMPTY_TMP`, `RELINK`, `WORKFLOWS`, `RESTORE`), code `2b7c82a`
(before skip_journal and the fixes below), `test_sample`. Every project ran, DOC, LABO, ICT, LLE, SOIL, SSPT, ROV,
GSPP and FPS for the first time.
- Counts: 14 projects created (01: 14 succeeded), 417 users, 60 groups, 44 statuses, 5 priorities matched (none
  created, decision 35), 28 trackers created and 3 updated; `members`: projects=14, 417 activated; 4,917 issues
  extracted, 4,912 created (5 held back, B77 and B78), 102 `[open-parent]`, no `[waiting]` issue; 6,852 files
  downloaded and uploaded, 0 failed, `paths.tmp` empty after 09 (8 KB left); 13,700 comments and 2,132 history
  entries pushed (48,949 history entries without a journal); 1,676 relations, 1 refused by Redmine (DOC-449 relates
  DOC-448 while DOC-448 blocks DOC-449: "circular dependency"), 2 MANUAL (the other issue not migrated); 13,595
  watchers.
- Durations: S0 to S1 45 min, S1 to S2 47 min, S2 to S3 2 h 41 min, after S3 16 min: 4 h 28 min (docs/PRODUCTION.md
  B8). 14 read every watcher from Jira twice (29 min each); its push now runs transform and push only (`97c8a53`).
- verify **FAIL** (6): the 5 held-back SURV issues (4 tracker/status and 1 total count) and DOC-516's revision number
  0 (B79). Found: B77, B78 (open, for Jan), B79; all but B78 fixed the same night. The B79 check of verify compares
  number fields since `da4f2c9`.
- B9 step 1, the relink check: 53 with `2b7c82a` (B76's false positives); with `e1cfa33` 570 texts with a Jira issue
  link, **0 to relink, 0 left**: in one run the relink is the check only, as decision 39 expects.
- Restore: 417 locked again, memberships equal 04's proposal, the workflow preview after it `+0 -0`, the global
  workflow of the existing tracker "Task" unchanged (4 rows, sha256 6c384767d3536829), 13 projects with their own
  workflow.
- The refactor of B76 (`4031877`) gives the same output as before over the 69,698 staged texts of this run.
- B77 proven on the instance: after `7f68c18`, 08 and 10 again (with the members step, then restore) created the 4
  SURV issues with their six vessels as separate values.

**Decision 37, the proof (B2, 2026-10-16 night).** Code `7f68c18` (skip_journal from `135fa96`, B76, B77, B79),
redmine_extended_api `de4ab98` (the lib of `ecc7dde`), on a Redmine that is not empty (the tracker "Task" with its own
global workflow, an existing project), `run_all.sh` with the one-run procedure, then 13 again on 3 issues and the
relink with work (`relink_fake.php`: keys of projects outside the run mapped onto an issue), measured by
`traces.py` (every journal on a migrated issue that 11 did not push; every issue whose updated time lies in the run;
the journals and issues whose times changed between a snapshot before 13 and the relink and after them):

| | 5.1: GEOS, SIS, OAC, DOC (1,030 issues) | 7.0: GEOS, SIS, OAC, DOC, SURV (2,527 issues, sample) |
|---|---|---|
| journals of post-processing (10's second pass, 12, 13, "Subtask added") | **0** (before the flag 211 for GEOS, SIS, OAC alone) | **0** |
| issues with an updated time in the run | **0** (before: 4, the 3 derived parents included) | **0** |
| 13 again on 3 issues and the relink of 15 (5.1) or 24 (7.0) texts | 0 new journals, 0 journals and 0 issues with new times | the same |
| the relink check after the run | 0 to relink of 49 texts | 0 of 262 |
| verify | FAIL 2: DOC-351's two comments, not read during a full disk (B81, fixed; 11 again read them) | **PASS**; `custom fields: number` 7 of 7 (B79) |
| the existing tracker "Task" | its global workflow removed by a stale list (B82, fixed) | 4 rows, sha256 6c384767d3536829, unchanged |

The 918 (7.0) and 183 (5.1) journals marked "Edited" are comments edited in Jira, which keep Jira's times. With the
flag the derived parents keep their updated time too (none in the run), so decision 38 costs nothing visible. The
7.0 run also had B78's advice in the test configuration (`agent-sessions` in `migration.custom_fields.not_migrated`,
open for Jan): "Agent Sessions" IGNORED, no Redmine field, SURV-1752 created; and B77 in a fresh run: "Offshore Vessel"
holds only the single vessels, SURV-1063 to 1066 created with six each. Found on the way: B80 to B83, all fixed and
proven on 5.1 (B80 and B83: DOC-292's checklist whole; B81: DOC-351's comments read again; B82: a stale list stops the
workflows step, the batch case passes). The first 7.0 attempt filled the disk: the proof script left the attachment
sample off and SURV's 98.7 GiB began to download; it ran again with the sample.
