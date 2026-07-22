// StoryCampaign — Scriptable iPhone widget.
//
// Setup:
//   1. In the app (logged in), run:  fetch('/widget/token', {method:'POST', headers:{'X-XSRF-TOKEN': ...}})
//      or simply visit Settings once — then copy your token from POST /widget/token.
//   2. Paste your base URL + token below.
//   3. In Scriptable: new script, paste this file, add a Small/Medium widget
//      pointing at it.
//
// The widget is a recent snapshot + tap-to-open, not real time — iOS decides
// when it refreshes.

const BASE_URL = "https://your-app-url.example";
const TOKEN = "paste-your-widget-token-here";

async function fetchStatus() {
  const req = new Request(`${BASE_URL}/api/widget/status?token=${encodeURIComponent(TOKEN)}`);
  req.timeoutInterval = 15;
  return await req.loadJSON();
}

function line(widget, text, size, color, italic = false) {
  const t = widget.addText(text);
  t.font = italic ? Font.italicSystemFont(size) : Font.systemFont(size);
  if (color) t.textColor = color;
  t.lineLimit = 3;
  return t;
}

const widget = new ListWidget();
widget.backgroundColor = new Color("#0a0a0c");
widget.setPadding(14, 14, 14, 14);

try {
  const status = await fetchStatus();

  if (!status.active) {
    line(widget, "StoryCampaign", 12, new Color("#8b8b93"));
    widget.addSpacer(4);
    line(widget, "No tale in progress.", 14, Color.white());
  } else {
    const title = widget.addText(status.character ?? status.campaign);
    title.font = Font.semiboldSystemFont(14);
    title.textColor = new Color("#e2dcc8");

    const h = status.health;
    if (h) {
      line(widget, `❤ ${h.current}/${h.max}`, 11, new Color("#9678dc"));
    }

    widget.addSpacer(6);
    line(widget, status.situation || "…", 12, new Color("#c9c9cf"), true);
    widget.addSpacer();

    if (status.awaiting_player) {
      line(widget, "◈ Your move.", 12, new Color("#7ee0a0"));
    } else if (status.resolves_at) {
      const ms = new Date(status.resolves_at).getTime() - Date.now();
      const mins = Math.max(0, Math.ceil(ms / 60000));
      line(widget, ms <= 0 ? "◈ The chapter is being written…" : `◈ Next chapter in ~${mins} min`, 12, new Color("#8b8b93"));
    }
  }

  widget.url = `${BASE_URL}/campaigns`;
} catch (e) {
  line(widget, "StoryCampaign", 12, new Color("#8b8b93"));
  widget.addSpacer(4);
  line(widget, "Couldn't reach the world.", 13, Color.white());
}

if (config.runsInWidget) {
  Script.setWidget(widget);
} else {
  await widget.presentSmall();
}
Script.complete();
