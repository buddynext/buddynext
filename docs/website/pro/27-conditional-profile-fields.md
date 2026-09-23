# Conditional Logic for Profile Fields (Pro)

Show a profile field only when a member picks a certain answer. Ask "Beard style" only when Gender is Male, or "Mentoring topics" only when "Open to mentoring" is ticked. The extra field appears and disappears the moment the answer changes, and looks like any other field.

> **Before you start:** Conditional logic comes with BuddyNext Pro. With Pro active, every eligible field's Add and Edit panel has an "Only show this field when" option. Nothing else needs switching on.

## Why use it

- **Shorter forms.** Members only see questions that apply to them.
- **Cleaner data.** No "N/A" answers, and no leftover answers that stopped applying.
- **Safe required fields.** A field is required only while it is shown, so it never blocks signup or a profile save.

## How it works (for members)

- **Instant.** Change the answer and the extra field shows or hides straight away. No save or reload needed.
- **Registration and the social-login "Almost there" page:** the extra field starts hidden and appears as soon as the matching answer is picked.
- **Edit Profile:** the page opens with the right fields already showing for the member's saved answers.
- **Changing your mind:** if a member switches the answer and the field hides, anything they typed stays in the box until they save. When they save, the answer to the hidden field is removed, so it never shows on their profile, in search or in the app.
- If every field in a section is hidden for a member, the whole section is hidden too.

The admin member editor (BuddyNext > Members > Edit) follows the same rules.

## Setting it up (for owners)

1. Go to **BuddyNext > Members > Profile Fields**.
2. Open **Add Field**, or the edit panel of an existing field.
3. Tick **Only show this field when**.
4. Pick the field that decides, choose **is** or **is not**, and tick the answers.
5. Check the summary ("Shown only when Gender is Male.") and save.

| Part | What you pick |
|---|---|
| Field that decides | A **Dropdown**, **Radio**, **Checkboxes**, **Yes / No** or **Member Type** field that is always shown |
| Comparison | **is** or **is not**. For Yes / No: **is Yes** or **is No**. |
| Answers | Tick one or more of that field's options |

### How the comparison works

| Rule | Shows when |
|---|---|
| Gender **is** Male | The member picked Male |
| Role **is** Manager, Director | The member picked either one |
| Skills **is** Leadership (Checkboxes) | Leadership is one of the ticked boxes |
| Gender **is not** Male | The member picked an answer, and it is not Male |
| Open to mentoring **is Yes** | The box is ticked |

A field whose deciding question has not been answered yet stays hidden, for both **is** and **is not**.

## Rules that keep it simple

- **One rule per field**, based on one other field.
- **One level.** A field that decides for others is always shown, and a field that is only shown sometimes cannot decide for another. The builder only offers fields that fit.
- **Registration.** If a field is asked on the registration form, the field that decides must be asked there too. The builder won't let you save it any other way, in either direction.
- **Where it is available.** Any field in a single-entry section can use conditional logic. Fields in sections with multiple entries (such as Work Experience), built-in system fields and the Member Type field itself are always shown.
- **Privacy is unchanged.** Each field keeps its own "Visible to" setting.

## What the field list tells you

| Badge | Meaning |
|---|---|
| **Conditional logic** | The field has a rule. Hover it to read the rule. |
| **Condition needs attention** | The field the rule depends on was deleted, can no longer decide, or lost the answers the rule checks. Until you fix it, the field is shown to everyone, so no member is ever blocked. |

The same problems, plus a registration setup that means a field would never show at signup, are listed in the **Some profile fields need attention** notice at the top of the Profile Fields screen. Each one has a **Fix** button that opens the field.

### What the builder will not let you save

| Situation | Message shown above Save |
|---|---|
| The rule has no field picked or no answers ticked | Asks you to finish the rule. |
| The field is asked on registration but the field that decides is not | Asks you to add that field to registration too, or stop asking this one there. |
| You untick registration on a field that registration fields depend on | Names the fields that depend on it. |
| The answers are member types members cannot pick themselves at signup | Asks you to stop asking the field at signup, or pick answers members can choose. |

## Good to know

- **Changing a rule later** (for example from "Manager or Director" to "Manager") removes answers that no longer apply in small background batches, so large communities stay fast.
- **Editing a field's choices** keeps its rule. If you remove an answer a rule relied on, you'll see the "needs attention" badge.
- **Connected apps follow the same rules.** They read them from the REST API below.

## For developers

`GET /wp-json/buddynext-pro/v1/profile-conditions` returns every rule:

```json
{
  "fields": [
    {
      "field_id": 42,
      "field_key": "beard_style",
      "condition": { "key": "gender", "kind": "single", "op": "in", "values": [ "male" ] },
      "summary": "Gender is Male"
    }
  ]
}
```

`op` is `in` (is) or `not_in` (is not). `kind` is `single`, `multi` or `boolean` (Yes / No answers are `1` and `0`). A field is shown when the member's answer to `key` matches; an unanswered `key` hides it. Rules that no longer apply are left out for visitors; administrators also see them with a `problem` explaining why.

### Hooks it is built on

Conditional logic uses only public Free hooks, so another add-on can build the same kind of feature:

| Hook | Used for |
|---|---|
| `buddynext_profile_field_settings` | The "Only show this field when" builder in the field panel |
| `buddynext_profile_field_options_sanitize` | Validating and storing the rule in the field's `options['conditions']` |
| `buddynext_profile_field_row_badges` | The **Conditional logic** / **Condition needs attention** badges |
| `buddynext_profile_field_setup_issues` | Problems listed in the "need attention" notice |
| `buddynext_profile_field_wrapper_attributes` / `buddynext_profile_group_wrapper_attributes` | The rule on each form field, and `hidden` for the first paint |
| `buddynext_profile_field_is_active` | Not requiring a hidden field (profile save and registration) |
| `buddynext_profile_saved`, `buddynext_member_type_assigned`, `buddynext_member_type_removed` | Clearing answers to fields that became hidden |

Full signatures: [Hooks: members, profiles and social](../developer-guide/28-hooks-members-profiles-social.md).

## Related

- [Profile Fields](../members/02-profile-fields.md) - the field builder this adds to.
- [Advanced Profile Field Types](09-advanced-profile-fields.md) - richer field types, which can also be shown conditionally.
- [Hooks: members, profiles and social](../developer-guide/28-hooks-members-profiles-social.md) - the form and save hooks this is built on.
