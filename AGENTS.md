# AGENTS.md

## Core Principles

Choose the simplest implementation that fully satisfies the current requirements.

Avoid speculative abstractions, configuration, indirection, and extensibility. Do not design for hypothetical future requirements. At the same time, avoid known architectural dead ends that would make the stated requirements unnecessarily difficult to extend or maintain.

## Compatibility and Cleanup

Unless backward compatibility is an explicit requirement, do not preserve it.

Remove obsolete code paths, interfaces, configuration, and tests instead of adding compatibility layers, fallbacks, aliases, or migrations. Update callers to use the current design directly.

Do not keep dead code or deprecated behavior “just in case.”

## Incremental Development

Grow the system in working layers.

Start with the smallest version that works end to end. Add each capability on top of a product that is already functional, tested, and internally consistent.

Prefer small, complete increments over broad, partially implemented architecture. Never replace a working system with unfinished complexity.

Intermediate implementations may be limited in scope, but production code should not be intentionally disposable or depend on a planned rewrite.

## Architecture and Modularity

Keep components modular and concerns clearly separated.

Introduce abstractions only when they remove demonstrated duplication, isolate a meaningful responsibility, or make the current implementation easier to understand and maintain.

Make architectural decisions that are appropriate for the known requirements and likely lifetime of the system. Do not accept a knowingly fragile stopgap merely because it is faster to implement.

## Dependencies

Prefer established, well-maintained libraries when they reduce overall complexity or improve reliability.

Before implementing functionality yourself or adding a new package:

1. Inspect the dependencies already used by the project.
2. Check the relevant documentation, source code, and type definitions.
3. Confirm that the required capability is not already available.

Do not reimplement common functionality without a clear project-specific reason. Do not add a dependency when the existing stack can solve the problem cleanly.

## Decision Priority

When principles appear to conflict, use this order of priority:

1. Correctly satisfy the explicit requirements.
2. Preserve a working end-to-end system.
3. Minimize complexity.
4. Maintain clear boundaries and long-term maintainability.
5. Avoid speculative flexibility and unnecessary compatibility.
