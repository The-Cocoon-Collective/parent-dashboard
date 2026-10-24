# parent-dashboard

> **Status: concept — architecture RFC open. There is no code in this repository yet.**
> The signal format it would consume is real and working:
> [harm-signal-api](https://github.com/The-Cocoon-Collective/harm-signal-api).

A reporting layer that aggregates safety signals across platforms. **Non-surveillance by
design: activity summaries, not keystroke logs.**

## Principles, in order of how hard they are to hold

1. **Parents see summaries, not surveillance.** "Played alone for four sessions after usually
   playing in a group of three" is useful. A transcript is not — it is a different product,
   for a different kind of parent, and we are not building it.
2. **No raw content, ever.** Signal classes and plain-English reasons only. The underlying
   schema cannot carry content, which is what makes this promise checkable rather than a
   policy.
3. **A child can see exactly what their parent sees.** Transparency by default. A child who
   does not know what is reported cannot trust the adults reading it, and a surveillance tool
   a child discovers later does more damage than the risk it was managing.
4. **Signals expire.** Nothing here becomes a permanent record of who a nine-year-old was.
5. **Platform-agnostic.** Any source that emits harm-signal-api signals can feed it, including
   platforms with no connection to us.

## Where it sits

```
a platform          its own store            harm-signal-api        this repo
(emits)        →    (private, always)   →    (analysis, public)  →  what a person sees
observations        the raw record           signals                summaries
```

## Intended stack

React and Tailwind on the front, Node and Express behind it, consuming the signal schema.
Stated as intent, not as something you can clone.

## What would help

The hard problem here is not the software. It is **what a parent should be told, and when,
and what a child is told at the same time** — including the case where naming a second child
to the first child's parent is itself a disclosure decision. The signal schema surfaces that
deliberately rather than hiding it. Views on how a dashboard should handle it are welcome in
the issues.

## Licence

MIT. See [LICENSE](LICENSE).
