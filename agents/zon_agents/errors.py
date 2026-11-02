"""The failures a caller must tell apart (mapped to HTTP statuses in main.py)."""


class DependencyUnavailable(Exception):
    """The embedding server, Qdrant or the model could not answer → 503, Laravel takes its fallback path."""

    def __init__(self, dependency: str) -> None:
        super().__init__(f"{dependency} unavailable")
        self.dependency = dependency


class InvalidPayload(Exception):
    """The input does not match the contract → 422, never retried."""

    def __init__(self, locations: list[str]) -> None:
        super().__init__(", ".join(locations))
        self.locations = locations
