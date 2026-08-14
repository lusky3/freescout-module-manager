/* global agent, args, log, phase, pipeline */
// The five identifiers above are injected by the Workflow tool's runtime when
// this script executes (see the Workflow tool's own documentation) -- they
// are not imported or defined anywhere in this file on purpose.

export const meta = {
  name: 'curate-catalog',
  description: 'Discover FreeScout modules on GitHub and safety-review each candidate for the module catalog',
  phases: [
    { title: 'Discover', detail: 'GitHub topic/keyword search plus the awesome-freescout list' },
    { title: 'Review', detail: 'one agent per candidate, applying the malicious-code checklist' },
    { title: 'Assemble', detail: 'merge safe verdicts into a catalog.json diff for human review' },
  ],
}

const REVIEW_CHECKLIST = `
Read the candidate repository's module.json and its main ServiceProvider (and any file it
directly includes). Reject the candidate (verdict: "reject") if you find ANY of:
1. Obfuscated or minified-only PHP with no readable source (e.g. base64-encoded blobs decoded
   and eval'd, single-line files with no whitespace).
2. eval(), assert() used as eval, create_function(), or a dynamic include()/require() of a
   remote URL or a path built from unvalidated input.
3. An outbound HTTP call to an endpoint that ISN'T either (a) obviously the module's own stated
   purpose and configured by the admin (e.g. a webhook URL the admin enters, matching this
   project's own GithubRepoFetcher pattern), or (b) a well-known, disclosed FreeScout-adjacent
   API (e.g. OpenAI for a GPT-drafting module). An undisclosed call to an unrelated third-party
   server is a reject.
4. Code that reads environment variables, .env contents, or app config values that look like
   credentials (API keys, DB passwords, mail credentials) and sends them anywhere, logs them,
   or writes them somewhere outside the module's own storage.
5. Filesystem writes outside the module's own directory tree (absolute paths, "../" segments,
   writes into FreeScout core's own app/ or public/ outside what nwidart-modules itself does).
6. No real module.json, or a module.json that doesn't point at a real, present ServiceProvider
   class (i.e., it's an abandoned template/fork with no actual implementation).
Otherwise verdict "safe". Write 1-2 sentences of review notes explaining what you checked,
matching the tone of the existing entries in Resources/catalog.json (this repository) --
concrete and specific, not "looks fine". Cite the exact suspicious line if you reject.
`

phase('Discover')
const searchResults = await agent(
  'Search GitHub as broadly and thoroughly as possible for FreeScout Laravel modules -- use ALL of these ' +
  'searches, not just one or two, since this pass is specifically meant to be deeper than a single obvious ' +
  'query: ' +
  '(1) mcp__github-mcp-server__search_repositories "freescout module in:name,description", sorted by stars; ' +
  '(2) mcp__github-mcp-server__search_repositories "topic:freescout-module"; ' +
  '(3) mcp__github-mcp-server__search_repositories "topic:freescout"; ' +
  '(4) mcp__github-mcp-server__search_repositories "freescout-module in:name" (hyphenated naming convention); ' +
  '(5) mcp__github-mcp-server__search_repositories "FreescoutModule in:name" and separately "FreeScoutModule in:name" ' +
  '(CamelCase naming conventions actually used by real modules already in the catalog, e.g. FreescoutDiscordModule); ' +
  '(6) mcp__github-mcp-server__search_code for `"freescout-help-desk/freescout" filename:composer.json` -- this ' +
  'finds real dependents of FreeScout core by their own declared composer dependency, catching modules that ' +
  'do not mention "freescout" anywhere in their name, description, or topics at all. ' +
  'For every repo found via searches (1)-(6), record owner, repo, stargazers_count, and pushed_at exactly as ' +
  'returned by the search API -- no extra per-repo lookup needed for these. ' +
  'Separately, fetch the README of avenjamin/awesome-freescout via mcp__github-mcp-server__get_file_contents ' +
  'and extract every github.com/owner/repo link it lists; for these, set from_awesome_list true and leave ' +
  'stars/pushed_at as 0/null if not otherwise known -- do not spend an extra lookup on them here, the Review ' +
  'phase already fetches full metadata per candidate. ' +
  'Combine every source, dedupe by owner/repo (case-insensitive), and drop anything already present in this ' +
  'repo\'s Resources/catalog.json (read that file first). ' +
  'Return a JSON array of candidates with owner, repo, stars (0 if unknown), pushed_at (null if unknown), and ' +
  'from_awesome_list (boolean, true only for candidates found via the awesome-freescout README).',
  {
    schema: {
      type: 'object',
      properties: {
        candidates: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              owner: { type: 'string' },
              repo: { type: 'string' },
              stars: { type: 'number' },
              pushed_at: { type: ['string', 'null'] },
              from_awesome_list: { type: 'boolean' },
            },
            required: ['owner', 'repo', 'stars', 'from_awesome_list'],
          },
        },
      },
      required: ['candidates'],
    },
  }
)

const allCandidates = searchResults.candidates
log(`${allCandidates.length} raw candidates found after dedup across all search strategies`)

// Recency/popularity filter, applied only to candidates found via raw GitHub
// search -- which can surface a lot of loosely-related, abandoned, or
// long-dead repos once the search net is widened like this. awesome-freescout
// entries are kept unconditionally: being on a maintained, curated community
// list is itself a relevance signal search alone can't provide, and the list
// is short enough that reviewing every entry on it isn't a meaningful cost.
const POPULARITY_STAR_THRESHOLD = 20
const RECENCY_CUTOFF_DAYS = 365
const MAX_CANDIDATES_TO_REVIEW = 40

const passesFilter = allCandidates.filter((c) => {
  if (c.from_awesome_list) return true
  if (c.stars >= POPULARITY_STAR_THRESHOLD) return true
  if (!c.pushed_at) return false
  const ageDays = (args.nowMs - new Date(c.pushed_at).getTime()) / (1000 * 60 * 60 * 24)
  return ageDays <= RECENCY_CUTOFF_DAYS
})

log(`${passesFilter.length} candidates pass the recency/popularity filter ` +
  `(dropped ${allCandidates.length - passesFilter.length} stale, low-star, non-awesome-list repos)`)

let candidates = passesFilter
if (candidates.length > MAX_CANDIDATES_TO_REVIEW) {
  candidates = [...passesFilter].sort((a, b) => b.stars - a.stars).slice(0, MAX_CANDIDATES_TO_REVIEW)
  log(`Capped review to the top ${MAX_CANDIDATES_TO_REVIEW} by stars ` +
    `(dropped ${passesFilter.length - MAX_CANDIDATES_TO_REVIEW} lower-star candidates past the cap -- ` +
    'a higher MAX_CANDIDATES_TO_REVIEW would review those too)')
}

phase('Review')
const reviewed = await pipeline(
  candidates,
  (candidate) => agent(
    `Fetch module.json and the main ServiceProvider file from ${candidate.owner}/${candidate.repo} via ` +
    `mcp__github-mcp-server__get_file_contents, plus the repo's stargazers_count, description, license, ` +
    `default_branch, and pushed_at via mcp__github-mcp-server__search_repositories or a direct repo lookup. ` +
    `Apply this checklist:\n${REVIEW_CHECKLIST}\nReturn structured data for this one candidate.`,
    {
      phase: 'Review',
      schema: {
        type: 'object',
        properties: {
          verdict: { type: 'string', enum: ['safe', 'reject'] },
          review_notes: { type: 'string' },
          name: { type: 'string' },
          description: { type: 'string' },
          author_name: { type: 'string' },
          stars: { type: 'number' },
          last_pushed_at: { type: 'string' },
          license: { type: 'string' },
          ref: { type: 'string' },
        },
        required: ['verdict', 'review_notes'],
      },
    }
  )
)

phase('Assemble')
// Pair each candidate with its review result BEFORE any filtering — filtering
// reviewed[] first (e.g. dropping nulls from a failed agent call) re-indexes
// the array, which would silently misattribute review results to the wrong
// candidate on every entry after the dropped one.
const paired = candidates.map((candidate, i) => ({ candidate, result: reviewed[i] }))

const safeEntries = paired
  .filter((r) => r.result && r.result.verdict === 'safe')
  .map((r) => ({
    owner: r.candidate.owner,
    repo: r.candidate.repo,
    ref: r.result.ref || 'main',
    name: r.result.name || r.candidate.repo,
    description: r.result.description || '',
    author_name: r.result.author_name || null,
    stars: r.result.stars || 0,
    last_pushed_at: r.result.last_pushed_at || null,
    license: r.result.license || null,
    screenshot_url: null,
    reviewed_at: null,
    review_notes: r.result.review_notes,
  }))

const rejected = paired.filter((r) => r.result && r.result.verdict === 'reject')
const failed = paired.filter((r) => !r.result)
log(`${safeEntries.length} safe, ${rejected.length} rejected, ${failed.length} failed out of ${candidates.length} reviewed`)

return { safeEntries, rejectedCount: rejected.length, totalCandidates: candidates.length }
