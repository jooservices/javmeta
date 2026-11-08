# Decision: cloud AI policy (JOOXCR-230)

Status: **ADOPTED** by the owner in the JOOXCR-258 implementation instructions (2026-10-24). Capture stays off until explicitly enabled in Settings; media AI stays local.

| AI step | Where it runs | Why |
|---|---|---|
| Embeddings of titles and descriptions | local (embedder or Ollama) | no content leaves the machine |
| Understanding a search question | local Ollama; a cloud model only if `ai.cloud_enabled` is on, and then it receives only the typed question, never a title, tag or image | the question is the owner's own text |
| Describing scenes from images | local vision model only | explicit images are never sent to a cloud provider; providers restrict this content and may keep the data |
| Speech recognition (subtitles) | local (Whisper on the GPU) only | audio of explicit video never leaves the machine |
| Translating subtitles | local LLM only | the text is explicit and is derived from the video |

Rules:

- A setting `ai.cloud_enabled` (Settings page, database) is **off**. Even when on, only the typed search question may go to a cloud model.
- Every cloud call is logged (what kind of step, how many characters, which provider) and never includes content from the catalog, from files or from images.
- A step that has no local model available fails with a clear message instead of falling back to the cloud.
- Changing this policy is an owner decision recorded as a comment on this ticket.
