---
name: dummy
description: Placeholder skill used to verify that skill discovery and loading work. Use only when the user explicitly says "dummy", "test skill", or asks to validate the skills pipeline.
---

# Dummy Skill

This is a dummy skill. It exists purely to prove that `read_skill` works end to end.
There is no real task logic here.

## When to Use

- You are validating that project skills are discovered and readable.
- The user explicitly asks to test the skill pipeline.

Otherwise, ignore this skill.

## Instructions

1. Acknowledge that the dummy skill was loaded successfully.
2. Echo back the skill name and the fact that its body was fetched from
   `skills/dummy/SKILL.md`.
3. Do not perform any other work as a side effect of loading this skill.

## Expected Result

The user sees confirmation that:

- `dummy` appears in the skill catalog, and
- its full body can be retrieved on demand.
