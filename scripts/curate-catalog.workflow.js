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
  'Search GitHub for FreeScout Laravel modules using mcp__github-mcp-server__search_repositories ' +
  'with queries like "freescout module in:name,description" (sort by stars) and "topic:freescout-module". ' +
  'Also fetch the README of avenjamin/awesome-freescout via mcp__github-mcp-server__get_file_contents and ' +
  'extract every github.com/owner/repo link it lists. Combine both sources, dedupe by owner/repo (case-' +
  'insensitive), and drop anything already present in this repo\'s Resources/catalog.json (read that file ' +
  'first). Return a JSON array of {owner, repo} pairs, nothing else.',
  { schema: { type: 'object', properties: { candidates: { type: 'array', items: { type: 'object', properties: { owner: { type: 'string' }, repo: { type: 'string' } }, required: ['owner', 'repo'] } } }, required: ['candidates'] } }
)

const candidates = searchResults.candidates
log(`${candidates.length} candidate repos found after dedup`)

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
