# Data Processing Agreement (template)

Status: a template for the agency's lawyer to review before the first client signs it. Fill in the [brackets]; the Greek version follows the lawyer's review. It describes what Social Ops actually does, so keep it in step with the code (CLAUDE.md lists the features).

---

## Data Processing Agreement

between **[Client legal name, address, VAT number]** (the "Controller") and **[Agency legal name, Chania, Crete, VAT number]** (the "Processor"), together "the Parties".

### 1. Background and scope
1.1 The Processor plans, creates, publishes and reports on the Controller's social media content under the services agreement dated [date] (the "Services Agreement"), using Social Ops, a system the Processor operates on its own server.
1.2 This Agreement sets the terms of Article 28(3) of Regulation (EU) 2016/679 (the "GDPR") for that processing. Greek Law 4624/2019 applies; where the Controller is established in Cyprus, Law 125(I)/2018 applies as well.
1.3 Annex 1 describes the processing, Annex 2 lists the sub-processors, Annex 3 the security measures.

### 2. Instructions
2.1 The Processor processes personal data only on the Controller's documented instructions: the Services Agreement, the Controller's brand profile in Social Ops, and the approvals the Controller gives in the approval portal.
2.2 Nothing is published to a social network before a person on the Controller's side has approved it in the portal (or, where the Controller chose the "optional" or "auto-approve on timeout" policy in its brand profile, after the period it set). Drafting with AI is processing, never publication.
2.3 The Processor informs the Controller when an instruction, in its opinion, infringes the GDPR.

### 3. Processor obligations
The Processor: (a) keeps the persons it authorises to process the data bound to confidentiality; (b) applies the measures of Annex 3; (c) engages sub-processors only as set out in clause 4; (d) assists the Controller with requests from data subjects (clause 6); (e) assists the Controller with Articles 32 to 36 GDPR, including breach notification (clause 7) and data protection impact assessments, with the information Social Ops holds; (f) deletes or returns the data at the end of the services (clause 8); (g) makes available the information needed to show compliance and allows audits (clause 9).

### 4. Sub-processors
4.1 The Controller gives a general authorisation for the sub-processors in Annex 2. The Processor binds each one to data protection terms no weaker than this Agreement.
4.2 The Processor notifies the Controller of a planned addition or replacement at least 14 days in advance by e-mail to the approver contacts in the brand profile. The Controller may object within that period on reasonable data protection grounds; the Parties then look for a solution, and failing one the Controller may terminate the affected services.
4.3 The social networks themselves (Meta for Instagram and Facebook, TikTok, Google for the Business Profile) are not sub-processors: the Controller holds its own accounts and agreements with them, and the Processor acts on those accounts through the access the Controller granted (OAuth). The same holds for Google Drive or Dropbox folders the Controller chooses to share with the Processor.

### 5. Location of the data and transfers
5.1 The application, its database and the media files are hosted in the European Union (Annex 2, hosting provider).
5.2 Where a sub-processor processes data outside the European Economic Area, the transfer rests on a Chapter V GDPR mechanism named in Annex 2 (an adequacy decision, including the EU-US Data Privacy Framework for certified companies, or the Standard Contractual Clauses).

### 6. Data subject requests
The Processor forwards to the Controller, within three working days, any request from a data subject it receives about the Controller's data (for example a comment author asking for deletion), and carries out the Controller's instructions on it. The Processor does not answer such requests itself except to refer the person to the Controller.

### 7. Personal data breach
The Processor notifies the Controller without undue delay, and at the latest within 48 hours of becoming aware of a personal data breach concerning the Controller's data, with the information of Article 33(3) GDPR as far as it is known, and completes it as it becomes known.

### 8. Deletion and return
8.1 At the end of the services the Processor, on the Controller's choice, returns the Controller's content (media files, captions and comments as a ZIP and CSV export) and then deletes the Controller's data from Social Ops within 30 days, including the connected accounts' access tokens, unless Union or Member State law requires storage.
8.2 Backups holding the data expire within [90] days of the deletion.

### 9. Audit
Once a year, with 30 days' notice, or without notice after a personal data breach, the Controller or an auditor it mandates (bound to confidentiality, not a competitor of the Processor) may verify compliance with this Agreement by reviewing the Processor's documentation, and where that is not enough, by an on-site inspection during business hours. Each Party bears its own costs.

### 10. Liability and term
10.1 Liability follows the Services Agreement, within the limits of Article 82 GDPR.
10.2 This Agreement applies as long as the Processor processes personal data for the Controller, and clauses 8 and 9 survive its end.

Signed in [place] on [date], in two originals.

For the Controller: ________________  For the Processor: ________________

---

## Annex 1: Description of the processing

| | |
|---|---|
| **Subject matter** | Social media management for the Controller: planning, drafting (Greek and English, with AI assistance), approval, scheduling, publishing, engagement and reporting for the accounts in the brand profile. |
| **Duration** | The term of the Services Agreement. |
| **Nature and purposes** | Storing the Controller's media and brand information; drafting and translating captions with Claude (Anthropic); collecting approvals; publishing to Instagram, Facebook and TikTok (LinkedIn is posted by hand); reading comments and direct messages and drafting replies; reading publishing statistics; monthly reports. |
| **Categories of data subjects** | The Controller's staff and approvers; customers and guests who appear in photos and videos (user-generated content, venue clips); people who comment on or message the Controller's accounts; people named in captions. |
| **Categories of personal data** | Names, e-mail addresses and phone numbers of approvers and staff; photos and videos, including faces, with the consent and repost-permission records the Controller gives; social media profile names and the text of comments, messages and reviews, with a sentiment and intent tag; access tokens for the Controller's accounts (encrypted); publishing statistics (aggregated numbers); captions and notes. |
| **Special categories** | None intended. The Controller does not provide content revealing health, religion or similar data (for example a customer's prescription in a pharmacy photo); the Processor flags such content to the Controller instead of using it. |
| **Retention in Social Ops** | Media and posts: until the Controller deletes them or the services end; a deleted post stays in the client's recycle bin until it is deleted for good there. Comments, messages and reviews: read from the networks for the last 30 days and kept until deleted. AI call records: [12] months, with the cost and size of each call. Access logs: [6] months. |

## Annex 2: Sub-processors

| Sub-processor | Service | Data concerned | Location and transfer basis |
|---|---|---|---|
| [Hosting provider of the Processor's server, legal name, country] | Hosting of the application, the database, the media files and backups | All data in Social Ops | [EU country], no transfer |
| Anthropic, PBC, San Francisco, USA | Claude API: drafting, shortening and rewriting captions, translation, comment triage and reply drafts, content planning, monthly and listening reports | Brand profile and notes, briefs, captions, the text of comments, messages and reviews with the author's profile name; no media | USA. Under Anthropic's commercial terms, API inputs and outputs are not used to train its models. Transfer basis: [Anthropic's EU-US Data Privacy Framework certification or the Standard Contractual Clauses in Anthropic's Data Processing Addendum, to confirm at anthropic.com/legal]. |
| Slack Technologies (Salesforce), USA, only while the Processor uses Slack alerts | Alerts to the Processor's team (a post failed, a comment needs a reply, a token expires) | The Controller's name, post names, counts and failure reasons; never media or message text | USA/EU. Transfer basis: [Data Privacy Framework certification / SCCs]. |
| [E-mail provider: Postmark, Resend, Amazon SES or the Processor's own mail server] | Approval requests, reminders, reports and alerts by e-mail | Approver names and addresses, post previews and captions, monthly numbers | [country and basis] |
| Dropbox, Inc., USA, only where the Controller shares a folder and the Processor's Dropbox account reads it | Reading the shared folder into the inbox | The files the Controller places in the folder | USA. The folder stays under the accounts of the Parties; Dropbox's own terms apply. |
| Google LLC (Google Drive), only where the Controller shares a folder with the Processor's service account | Reading the shared folder into the inbox | The files the Controller places in the folder | EU/USA under Google's terms. |
| Canva Pty Ltd, Australia, only where the Processor imports designs from its own Canva | Export of finished designs into the inbox | Design files, no personal data beyond what the design shows | Australia, under Canva's terms. |

Not sub-processors (the Controller's own providers): Meta Platforms Ireland (Instagram, Facebook), TikTok Technology Limited, LinkedIn Ireland.

## Annex 3: Technical and organisational measures (as built in Social Ops)

- **Access to accounts**: the Controller's social accounts are connected through the networks' own OAuth consent; no passwords are ever stored. Access tokens are encrypted at rest with the application key, checked daily and renewed through the networks' own refresh mechanisms. The Controller can revoke access at any time in the network's settings.
- **Isolation**: every record (accounts, media, posts, comments, reports) carries the client it belongs to; staff logins can be limited to named clients, and then see only those clients' work and cannot add clients; API tokens are hashed and can be limited to one client. The API and the Claude connector are rate limited per token, and the connector's sign-up per address.
- **Approvers and clients without accounts**: approvers use signed links that expire; upload links expire after 30 days; account-connection links after 7 days; client calendar and report links after 30 days. No public registration exists.
- **Human approval gate**: AI drafts, a person approves, nothing is published without approval (clause 2.2).
- **Consent records**: each media file carries whether the people in it consented and whether reposting the content was permitted, as the Controller states on upload.
- **Hosting and transport**: EU server, HTTPS only, the database on the same server, [Plesk backup schedule and retention].
- **Logging and alerts**: queue jobs and AI calls are logged with their cost and outcome; alerts to the Processor's team carry names and counts, never media.
- **Deletion**: deleting a client in Social Ops removes its accounts, media records, posts, comments and reports; the media files on disk are removed at offboarding (clause 8).
- **People**: [staff bound by confidentiality clauses in their contracts; access limited to the staff working on the Controller].

---

## Short sub-processor list for the privacy notice

Hosting: [provider, EU]. AI drafting: Anthropic (USA, Claude API). Alerts: Slack (when enabled). E-mail: [provider]. File intake, only when the client shares a folder: Google Drive, Dropbox. Design import: Canva. The social networks themselves are the client's own providers.
