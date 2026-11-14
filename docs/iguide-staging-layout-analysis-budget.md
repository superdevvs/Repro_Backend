# Staging layout analysis budget candidate

The retained production layout request used GPT-6 Astra with high reasoning effort and a 12,000-token output limit. Its HTTP200 response was incomplete because max_output_tokens was exhausted; usage reported12,000 reasoning tokens and no structured answer. No image was requested. That record and its immutable v1 recipe remain unchanged.

This candidate registers `scene-instructions-staging-layout-2`: medium effort with the same12,000-token ceiling. The model, strict JSON schema, instructions, compiler, parser, exact ordered source/context images and all semantic/geometry gates are unchanged. The old recipe file is byte-identical. Only the recipe identity and effort differ; the compiled request differs only in reasoning.effort.

The workflow still selects v1. This registration adds no automatic retry, provider call, configuration switch or analysis allowance. The four-analysis staging budget and downstream source, placement, support, native composition and independent quality requirements remain intact. Promotion requires measured diagnostic evidence and a separate reviewed runtime/selection change.

The standalone diagnostic plan first submits medium/12k against the exact retained input. Only if it again exhausts max_output_tokens, a medium/25k comparison tests additional headroom before reducing effort. Low/12k is an optional later explicit comparison. A truthful needs-review or native-detail/reframe request is a valid structured result, not a reason to force a different answer. No pilot result becomes an applied scene analysis or authorizes an image in the failed workflow.

OpenAI documents that the output budget includes reasoning and can expire before visible output. Its experimentation guidance recommends at least25,000 tokens; here the initial12k request deliberately changes one variable before that conditional comparison. Lower reasoning effort generally favors latency and fewer reasoning tokens; quality and actual completion still need measurement.

Sources: https://developers.openai.com/api/docs/guides/reasoning and https://developers.openai.com/api/docs/guides/deployment-checklist .
