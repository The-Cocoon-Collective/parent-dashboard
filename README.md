# parent-dashboard

A unified reporting layer that aggregates safety signals across platforms.
Non-surveillance by design: activity summaries, not keystroke logs.

## Status
Coming Soon — architecture RFC open

## Principles
- Parents see summaries, not surveillance
- No raw message content — signal categories only
- Child can see exactly what parent sees (transparency by default)
- Platform-agnostic: works with any source that implements the harm-signal-api

## Stack
- Frontend: React + Tailwind
- Backend: Node / Express
- Signal source: harm-signal-api

## License
MIT
