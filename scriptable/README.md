# Scriptable widget

The StoryCampaign iPhone widget script lives in the shared Scriptables
project, not in this repo:

`C:\Users\binar\OneDrive\Documents\Claude\Projects\Scriptables\widgets\story-campaign.js`

`StoryCampaignWidget.js.lnk` is a Windows shortcut to that file. The script
is kept out of this repo on purpose — its CONFIG block carries a personal
widget token once set up, which must never be committed. It reads
`GET /api/widget/status?token=…` (token from `POST /widget/token`).
