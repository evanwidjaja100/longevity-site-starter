# Claim Management

Create a claim record for every material efficacy, safety, contraindication, interaction, regulatory, specification, price, warranty, privacy, accuracy, usability, first-hand observation, or editorial-interpretation claim.

Each record identifies the article, exact claim, location, importance, source, population/intervention/comparator/outcome where relevant, evidence design and grade, conflicts, verification state, verifier, date, recheck date, and supersession link.

Use `wp longevity claims validate` before import. Use `--dry-run` for imports, preserve stable IDs, and resolve duplicates explicitly. CSV exports neutralize spreadsheet formulas. Do not store full copyrighted articles; retain metadata, lawful excerpts where necessary, archived references, and editorial notes.
## Production Readiness v2 claim verification

Claim editing and claim verification use separate capabilities. Each governed claim records its last editor and edit time, verifier and verification time, and a canonical verification snapshot hash. Where independent verification is required, the verifier cannot be the last editor. A material claim or linked-source change invalidates the claim verification and any dependent fact-check, medical, or editorial approval.

Claim and source records remain private WordPress records. Raw records, internal IDs, notes, and provenance are not anonymous REST contracts; only an explicitly approved public projection may surface claim evidence.
