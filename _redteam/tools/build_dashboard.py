#!/usr/bin/env python3
"""
build_dashboard.py — generator HTML dashboardu wynikow red-team.

Uzycie:
    python3 tools/build_dashboard.py reports/run_YYYY-MM-DDTHHMMSSZ.json
    python3 tools/build_dashboard.py reports/run_X.json -o reports/dashboard.html

Produkuje self-contained HTML (dane wbudowane). HTML zawiera transcripty
(potencjalne wrazliwe tresci) -> wynikowy plik jest gitignored jak reports/*.json.
Sam generator (ten plik) jest wersjonowany.
"""
import argparse, json, sys
from pathlib import Path

CANARY = {"HALLU-001", "HALLU-002", "DOMAIN-002"}


def extract(run: dict) -> dict:
    """Mapuje pelny run JSON (klucz 'records' z polami scenariusza) do lekkiego formatu dashboardu."""
    records = run.get("records") or run.get("results") or []
    out = []
    for r in records:
        w1 = r.get("w1") or {}
        crit = w1.get("criteria") if isinstance(w1, dict) else None
        out.append({
            "id": r.get("scenario_id"), "cat": r.get("category"), "sev": r.get("severity"),
            "title": r.get("title"), "seed": r.get("introduced_by"),
            "verdict": r.get("final_verdict"), "layer": r.get("final_layer"),
            "fail_axes": r.get("fail_axes") or [],
            "tools": r.get("tools_used") or [], "dur": round(r.get("duration_sec", 0) or 0, 1),
            "turns": r.get("transcript") or [],
            "w0": r.get("w0") or {},
            "w1_criteria": crit or [],
            "canary": r.get("scenario_id") in CANARY,
        })
    meta = {"run_id": run.get("run_id"), "gt": run.get("ground_truth_available"),
            "agg": run.get("aggregate", {})}
    return {"meta": meta, "records": out}


TEMPLATE = r"""<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Red-team dashboard — DiveChat</title>
<style>
:root{
  --bg:#0f1116; --panel:#171a21; --panel2:#1e222b; --border:#2a2f3a;
  --txt:#e6e9ef; --muted:#9aa3b2; --accent:#4f9dff;
  --pass:#2ea043; --fail:#f85149; --uv:#d29922; --err:#bb8009;
  --s0:#f85149; --s1:#d29922; --s2:#58a6ff;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--txt);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:0 0 60px}
header{background:linear-gradient(180deg,#171a21,#0f1116);border-bottom:1px solid var(--border);padding:18px 24px;position:sticky;top:0;z-index:50}
h1{font-size:18px;font-weight:650;display:flex;align-items:center;gap:10px}
h1 .tag{font-size:11px;font-weight:500;color:var(--muted);background:var(--panel2);padding:3px 8px;border-radius:6px;border:1px solid var(--border)}
.sub{color:var(--muted);font-size:12px;margin-top:4px}
.wrap{max-width:1400px;margin:0 auto;padding:20px 24px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.stat{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.stat .n{font-size:26px;font-weight:700}
.stat .l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.stat.pass .n{color:var(--pass)} .stat.fail .n{color:var(--fail)} .stat.uv .n{color:var(--uv)}
.controls{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.controls input,.controls select{background:var(--panel2);border:1px solid var(--border);color:var(--txt);padding:8px 12px;border-radius:8px;font-size:13px}
.controls input{flex:1;min-width:200px}
.chip{padding:6px 12px;border-radius:20px;border:1px solid var(--border);background:var(--panel2);color:var(--muted);cursor:pointer;font-size:12px;user-select:none}
.chip.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.drop{font-size:11px;color:var(--muted);border:1px dashed var(--border);padding:6px 12px;border-radius:8px;cursor:pointer}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden}
th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid var(--border);cursor:pointer;white-space:nowrap}
th:hover{color:var(--txt)}
td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:top}
tr.row{cursor:pointer;transition:background .1s}
tr.row:hover{background:var(--panel2)}
tr.row.open{background:var(--panel2)}
.id{font-family:ui-monospace,Menlo,monospace;font-weight:600;font-size:12px;scroll-margin-top:90px}
.canary-star{color:var(--uv)}
.badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:600}
.sev-S0{background:rgba(248,81,73,.15);color:var(--s0)}
.sev-S1{background:rgba(210,153,34,.15);color:var(--s1)}
.sev-S2{background:rgba(88,166,255,.15);color:var(--s2)}
.v-pass{background:rgba(46,160,67,.15);color:var(--pass)}
.v-fail{background:rgba(248,81,73,.15);color:var(--fail)}
.v-unable_to_verify{background:rgba(210,153,34,.12);color:var(--uv)}
.v-orchestrator_error{background:rgba(187,128,9,.15);color:var(--err)}
.layer{font-family:ui-monospace,monospace;font-size:11px;color:var(--muted);text-transform:uppercase}
.cat{font-size:12px;color:var(--muted)}
.axes{font-size:11px;color:var(--fail)}
.detail{background:#0c0e13;border-bottom:1px solid var(--border)}
.detail td{padding:0}
.dwrap{padding:18px 24px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
.turn{margin-bottom:14px}
.turn .phase{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--accent);margin-bottom:6px;font-weight:600}
.bubble{padding:10px 14px;border-radius:10px;margin-bottom:6px;white-space:pre-wrap;font-size:13px}
.bubble.attack{background:rgba(248,81,73,.08);border-left:3px solid var(--fail)}
.bubble.bot{background:rgba(79,157,255,.06);border-left:3px solid var(--accent)}
.bubble .who{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:4px}
.panel-box{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px}
.panel-box h3{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px}
.crit{border-left:3px solid var(--border);padding:8px 12px;margin-bottom:10px;background:var(--panel2);border-radius:0 8px 8px 0}
.crit.fail{border-color:var(--fail)} .crit.pass{border-color:var(--pass)} .crit.unable_to_verify{border-color:var(--uv)}
.crit .ax{font-weight:600;font-size:12px;display:flex;justify-content:space-between}
.crit .rs{font-size:12px;color:var(--muted);margin-top:4px}
.conf{font-size:10px;color:var(--muted)}
.meta-row{font-size:12px;color:var(--muted);margin-bottom:6px}
.meta-row b{color:var(--txt);font-weight:600}
.w0hit{background:rgba(248,81,73,.1);border:1px solid var(--fail);border-radius:8px;padding:10px 12px;font-size:12px;margin-bottom:10px}
.w0hit code{background:#000;padding:1px 5px;border-radius:4px;color:var(--fail)}
.empty{color:var(--muted);font-style:italic;font-size:12px}
.note{background:rgba(210,153,34,.08);border:1px solid var(--uv);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--txt);margin-bottom:16px}
.tools-tag{display:inline-block;background:var(--panel2);border:1px solid var(--border);padding:1px 7px;border-radius:5px;font-size:11px;font-family:ui-monospace,monospace;margin-right:4px}
</style>
</head>
<body>
<header>
  <h1>Red-team dashboard <span class="tag" id="runtag">—</span> <span class="tag" id="gttag">—</span></h1>
  <div class="sub">DiveChat harness — atak, odpowiedź bota, ocena sędziego. Kliknij wiersz, by rozwinąć transcript.</div>
</header>
<div class="wrap">
  <div class="note" id="globalnote"></div>
  <div class="stats" id="stats"></div>
  <div class="controls">
    <input id="search" placeholder="Szukaj: ID, tytuł, treść ataku…">
    <span class="chip active" data-f="all">Wszystkie</span>
    <span class="chip" data-f="fail">Tylko fail</span>
    <span class="chip" data-f="unable_to_verify">Tylko UV</span>
    <span class="chip" data-f="pass">Tylko pass</span>
    <span class="chip" data-f="canary">Canary</span>
    <select id="sevsel"><option value="">Wszystkie severity</option><option>S0</option><option>S1</option><option>S2</option></select>
    <label class="drop">Wczytaj inny run (JSON)<input type="file" id="file" accept=".json" hidden></label>
  </div>
  <table>
    <thead><tr>
      <th data-s="id">ID</th><th data-s="sev">Sev</th><th data-s="cat">Klasa</th>
      <th data-s="verdict">Werdykt</th><th data-s="layer">Warstwa</th>
      <th>Osie fail</th><th>Narzędzia</th><th data-s="dur">Czas</th>
    </tr></thead>
    <tbody id="tbody"></tbody>
  </table>
</div>
<script>
const DATA = __PAYLOAD__;
</script>
<script>
let recs = DATA.records.slice();
let filter='all', sevFilter='', q='', sortKey='sev', sortDir=1, openId=null;
function esc(s){return (s==null?'':String(s)).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}
function renderMeta(){
  document.getElementById('runtag').textContent = DATA.meta.run_id || 'run';
  document.getElementById('gttag').textContent = 'ground truth: ' + (DATA.meta.gt ? 'dostępny' : 'BRAK (106b)');
  const n=document.getElementById('globalnote');
  n.innerHTML = '⚠ <b>Czytaj transcript, nie samą etykietę.</b> Werdykt „fail" bywa false-positive W0 (regex łapie słowa-klucze w odmowach, np. DOMAIN-004) lub artefaktem zbyt ostrożnego sędziego. Liczby zbiorcze są orientacyjne dopóki rubryka W1 nie jest skalibrowana (T-025 meta-eval).';
}
function computeStats(){
  let p=0,f=0,u=0,e=0,s0f=0;
  recs.forEach(r=>{ if(r.verdict==='pass')p++; else if(r.verdict==='fail'){f++; if(r.sev==='S0')s0f++;} else if(r.verdict==='unable_to_verify')u++; else e++; });
  return {p,f,u,e,s0f,total:recs.length};
}
function renderStats(){
  const s=computeStats();
  document.getElementById('stats').innerHTML = [
    ['',s.total,'scenariuszy',''],
    ['pass',s.p,'pass','pass'],
    ['fail',s.f,'fail','fail'],
    ['uv',s.u,'unable to verify','uv'],
    ['s0f',s.s0f,'S0 fail (priorytet)','fail'],
  ].map(([k,n,l,c])=>`<div class="stat ${c}"><div class="n">${n}</div><div class="l">${l}</div></div>`).join('');
}
const SEVORD={S0:0,S1:1,S2:2};
function sorted(arr){
  return arr.slice().sort((a,b)=>{
    let x,y;
    if(sortKey==='sev'){x=SEVORD[a.sev]??9;y=SEVORD[b.sev]??9;}
    else if(sortKey==='dur'){x=a.dur;y=b.dur;}
    else {x=(a[sortKey]||'')+'';y=(b[sortKey]||'')+'';}
    if(x<y)return -1*sortDir; if(x>y)return 1*sortDir; return 0;
  });
}
function visible(){
  return sorted(recs).filter(r=>{
    if(filter==='canary'&&!r.canary)return false;
    if(['fail','pass','unable_to_verify'].includes(filter)&&r.verdict!==filter)return false;
    if(sevFilter&&r.sev!==sevFilter)return false;
    if(q){const hay=(r.id+' '+r.title+' '+r.cat+' '+JSON.stringify(r.turns)).toLowerCase();if(!hay.includes(q.toLowerCase()))return false;}
    return true;
  });
}
function detailHTML(r){
  let turns = r.turns.map(t=>`
    <div class="turn">
      <div class="phase">tura ${t.turn}${t.phase?' · '+esc(t.phase):''}</div>
      <div class="bubble attack"><div class="who">atak (user)</div>${esc(t.user)}</div>
      <div class="bubble bot"><div class="who">bot</div>${esc(t.assistant)}</div>
    </div>`).join('');
  let evalHTML='';
  if(r.w0 && r.w0.hit){
    evalHTML += '<div class="w0hit"><b>W0 regex hit</b><br>'+ (r.w0.hits||[]).map(h=>
      `klasa <code>${esc(h.class)}</code> (${esc(h.severity)}) — dopasowanie: <code>${esc(h.match)}</code> w turze ${h.turn}`).join('<br>') +'</div>';
  }
  if(r.w1_criteria && r.w1_criteria.length){
    evalHTML += '<div class="panel-box"><h3>Sędzia W1 (gpt-5.4)</h3>'+
      r.w1_criteria.map(c=>`<div class="crit ${esc(c.decision)}"><div class="ax"><span>${esc(c.axis)}</span><span class="badge v-${esc(c.decision)}">${esc(c.decision)}</span></div>${c.confidence!=null?'<div class="conf">confidence: '+c.confidence+'</div>':''}<div class="rs">${esc(c.reasoning)}</div></div>`).join('')+
      '</div>';
  } else if(!(r.w0&&r.w0.hit)){
    evalHTML += '<div class="panel-box"><h3>Ocena</h3><div class="empty">Brak szczegółów oceny (werdykt: '+esc(r.verdict)+').</div></div>';
  }
  return `<div class="dwrap">
    <div><div class="meta-row"><b>${esc(r.title)}</b></div>
      <div class="meta-row">seed: ${esc(r.seed)||'—'} · narzędzia: ${(r.tools||[]).map(t=>'<span class="tools-tag">'+esc(t)+'</span>').join('')||'—'} · czas: ${r.dur}s</div>
      ${turns}</div>
    <div>${evalHTML}</div>
  </div>`;
}
function render(){
  const vis=visible();
  const tb=document.getElementById('tbody');
  tb.innerHTML = vis.map(r=>{
    const star = r.canary?'<span class="canary-star" title="canary">★</span> ':'';
    const axes = (r.fail_axes||[]).filter(a=>a!=='w0_regex_hit').join(', ');
    const main = `<tr class="row ${openId===r.id?'open':''}" data-id="${esc(r.id)}">
      <td class="id">${star}${esc(r.id)}</td>
      <td><span class="badge sev-${esc(r.sev)}">${esc(r.sev)}</span></td>
      <td class="cat">${esc(r.cat)}</td>
      <td><span class="badge v-${esc(r.verdict)}">${esc(r.verdict)}</span></td>
      <td class="layer">${esc(r.layer)}</td>
      <td class="axes">${esc(axes)||'—'}</td>
      <td>${(r.tools||[]).map(t=>'<span class="tools-tag">'+esc(t)+'</span>').join('')||'<span class="empty">—</span>'}</td>
      <td>${r.dur}s</td></tr>`;
    const det = openId===r.id?`<tr class="detail"><td colspan="8">${detailHTML(r)}</td></tr>`:'';
    return main+det;
  }).join('');
  tb.querySelectorAll('tr.row').forEach(tr=>tr.onclick=()=>{
    const id=tr.dataset.id; const wasOpen=openId===id; openId=wasOpen?null:id; render();
    if(!wasOpen){
      // FIX scrolla: po rozwinieciu ustaw widok na POCZATEK klikanego wiersza, nie koniec detalu
      requestAnimationFrame(()=>{
        const el=document.querySelector('tr.row.open .id');
        if(el) el.scrollIntoView({block:'start',behavior:'smooth'});
      });
    }
  });
}
document.getElementById('search').oninput=e=>{q=e.target.value;render();};
document.getElementById('sevsel').onchange=e=>{sevFilter=e.target.value;render();};
document.querySelectorAll('.chip').forEach(c=>c.onclick=()=>{
  document.querySelectorAll('.chip').forEach(x=>x.classList.remove('active'));
  c.classList.add('active'); filter=c.dataset.f; render();
});
document.querySelectorAll('th[data-s]').forEach(th=>th.onclick=()=>{
  const k=th.dataset.s; if(sortKey===k)sortDir*=-1; else{sortKey=k;sortDir=1;} render();
});
document.getElementById('file').onchange=e=>{
  const f=e.target.files[0]; if(!f)return;
  const rd=new FileReader();
  rd.onload=()=>{ try{
    const j=JSON.parse(rd.result);
    if(j.records){ DATA.meta=j.meta||{run_id:f.name}; recs=adapt(j.records); openId=null; renderMeta();renderStats();render(); }
    else alert('Nieznany format JSON (oczekiwano klucza records).');
  }catch(err){alert('Błąd parsowania: '+err);} };
  rd.readAsText(f);
};
function adapt(records){
  const canary=new Set(['HALLU-001','HALLU-002','DOMAIN-002']);
  return records.map(r=>{
    if(r.turns) return r;
    const w1=r.w1||{};
    return {id:r.scenario_id,cat:r.category,sev:r.severity,title:r.title,seed:r.introduced_by,
      verdict:r.final_verdict,layer:r.final_layer,fail_axes:r.fail_axes||[],tools:r.tools_used||[],
      dur:Math.round((r.duration_sec||0)*10)/10,turns:r.transcript||[],w0:r.w0||{},
      w1_criteria:(w1.criteria)||[],canary:canary.has(r.scenario_id)};
  });
}
renderMeta();renderStats();render();
</script>
</body>
</html>"""


def main():
    ap = argparse.ArgumentParser(description="Generator HTML dashboardu red-team.")
    ap.add_argument("run_json", help="Sciezka do run_*.json")
    ap.add_argument("-o", "--output", default=None, help="Plik wyjsciowy HTML")
    args = ap.parse_args()

    run_path = Path(args.run_json)
    if not run_path.exists():
        print(f"Nie znaleziono: {run_path}", file=sys.stderr)
        return 1
    run = json.loads(run_path.read_text(encoding="utf-8"))
    data = extract(run)
    payload = json.dumps(data, ensure_ascii=False)
    html = TEMPLATE.replace("__PAYLOAD__", payload)

    out = Path(args.output) if args.output else run_path.with_name("dashboard.html")
    out.write_text(html, encoding="utf-8")
    print(f"OK -> {out}  ({len(data['records'])} scenariuszy, {round(len(html)/1024,1)} KB)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
