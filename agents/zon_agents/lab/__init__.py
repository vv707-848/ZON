"""The model lab: the same golden cases, through the real pipelines, against any model profile.

A case is what Laravel sends to one agent (`input`) and what a right answer must satisfy (`expect`), written by
people who know the market — never by the model being measured. The runner calls the production pipeline (same
prompt, same schema, same checks) with a fixed retrieval, so only the model differs between two runs; the scorers
are deterministic (no model judges another). Variants test what a good model must not care about: the order of the
candidates, the form of the digits, an instruction hidden in an advertiser's text.

    zon-agents lab check                        # the cases are valid
    zon-agents lab run --profile <name> --gpu A40 --out lab-<name>.json
"""
