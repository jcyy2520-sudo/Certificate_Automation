# Google Forms registration blueprint

This is the approved external-form configuration for the public Webinar
Registration Form reviewed on 2026-09-25. It is a human-operated form, not an
application-managed resource: changes must be made by the form owner in Google
Forms. The application accepts its CSV response export after the administrator
performs the normal dry check and column review.

## Identity fields required for certificate automation

Use these exact questions in every event's registration form. The exact
punctuation may change, because the import mapper is reviewed per file, but
the meaning must not change.

### Full Name for Certificate

- Type: short answer
- Required: yes
- Description:

  ```text
  Example: Dela Cruz, Maria L.
  Use the exact spelling, capitalization, middle initial, and suffix you want
  printed on your certificate.
  ```

The participant master record is the only source of a certificate name. The
application preserves the submitted spelling; it does not guess name order or
silently correct a name.

### Email Address for Webinar and Certificate

- Type: short answer
- Required: yes
- Response validation: text -> email address
- Description:

  ```text
  Use this same email address for the Pre-Test, Post-Test, and Evaluation.
  It is used to match your responses and send any certificate.
  ```

Use a manually entered, email-validated question consistently across all four
forms. Do not use an automatically collected Google-account email in one form
and a manual email in another, because a participant's Google account may not
be the address they intend for certificate delivery.

## Recommended registration statement

Add this sentence to the form description:

```text
Registration alone does not guarantee a certificate. Certificate eligibility
requires completion of the Pre-Test, Post-Test, and Evaluation using the same
email address.
```

## Data minimization

The certificate workflow needs only certificate name, email, and optionally an
organization. It does not need age, gender, contact number, year level,
referral source, learning expectation, or prior-webinar experience.

Keep one of those fields only when there is a real, documented operational or
reporting purpose. Otherwise remove it or make it optional. In particular,
age, gender, and contact number are additional personal data and do not improve
participant matching, eligibility, certificate generation, or delivery.

If `Gender` is retained and exactly one answer is intended, use a multiple
choice question rather than a checkbox question.

## Consent configuration

Do not combine essential event processing and future-marketing consent into a
single required checkbox group. Prefer separate confirmations:

```text
[Required] I confirm that the information I provided is accurate.

[Required] I understand that my information will be used for webinar
administration, eligibility verification, certificate processing, and related
event communication.

[Optional] I agree to receive future webinar announcements and updates.
```

A required checkbox-group question can otherwise be interpreted as requiring
only one selected item. The form owner remains responsible for the applicable
privacy notice, lawful basis, retention, and consent requirements.

## CSV mapping for the reviewed current form

| Google Forms export header | Application mapping | Notes |
| --- | --- | --- |
| `Timestamp` | Submitted at | Choose the actual timestamp order/timezone during dry check. |
| `Full Name: (Surname, Firstname, MI)` | Full name | The mapper now recognizes this header shape. It prints exactly as entered. |
| `Email Address:` | Email address | Primary identity key; required in all four forms. |
| `School/University/Organization:` | Organization | Optional metadata; not certificate text by default. |
| `Age`, `Gender`, `Contact Number`, `Course/Program/Position`, `Year Level`, referral, expectation, prior-webinar, consent columns | Leave unmapped | They are not needed for certificate automation. |

Before every import, run the application's dry check. Review the displayed
names, email addresses, timestamps, and held-row reasons. The system cannot
prove that an uploaded CSV originated from the claimed Google Form; the form
owner must confirm the source and destination during import.

## Requirements for the other three forms

Pre-Test, Post-Test, and Evaluation must all include the same required,
validated manual email question. They do not need to ask for a name if
registration is required operationally, but they should not collect a
different email identity.

- Pre-Test and Post-Test: include a Google Quiz score or selected required
  completion-answer column in the export.
- Evaluation: include at least one required question/answer column. A
  summary-only CSV cannot establish completion.
- Use the same event title/version in each form description so the organizer
  can identify exports during review.

Operationally, require people to register before the other forms, even though
registration is not part of the default certificate eligibility rule. It
creates the participant master record and captures the authoritative
certificate name. The certificate rule remains: Pre-Test + Post-Test +
Evaluation.
