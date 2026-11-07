<?php
// panel admina musi zawsze przychodzić z sieci: zawiera listę zdjęć, komentarzy
// i kont, a stary dokument z cache przeglądarki ukrywa nowe funkcje
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', false);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>zmogilna — panel administracyjny</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
<meta name="theme-color" content="#101c16" id="metaThemeColor">
<script>
  // motyw zapamiętany w przeglądarce (ten sam klucz co na stronie głównej)
  (function(){
    try{
      var t = localStorage.getItem('zmogilna-theme');
      if(t === 'light'){
        document.documentElement.setAttribute('data-theme', 'light');
        document.getElementById('metaThemeColor').setAttribute('content', '#f5f1e6');
      }
    }catch(_){}
  })();
</script>
<link rel="stylesheet" href="zmogilna-ui.css">
<style>
  :root{
    --bg:#101c16; --bg-soft:#16241c; --card:#18271f; --ink:#ece6d6; --ink-dim:#96a397; --gold:#c9a227; --clay:#a65a3c; --teal:#5a8a86; --line:rgba(236,230,214,.10); --line-strong:rgba(236,230,214,.22); --bg-alt:#16241c;
  }
  html{color-scheme:dark}
  *{box-sizing:border-box}
  html{scroll-behavior:smooth}
  body{margin:0; min-height:100vh; background:var(--bg); color:var(--ink); font-family:'Inter',sans-serif; -webkit-font-smoothing:antialiased}
  a{color:inherit}
  button,input,select,textarea{font:inherit}
  .wrap{max-width:920px; margin:0 auto; padding:0 20px}
  header.top{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:22px 0; border-bottom:1px solid var(--line)}
  .wordmark{font-family:'Fraunces',serif; font-size:20px; font-weight:600}
  .wordmark a{text-decoration:none}
  .wordmark .sub{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); font-weight:400; margin-left:8px}
  .header-actions{display:flex; align-items:center; gap:8px; flex-wrap:wrap}
  .home-link,.logout-btn,.tool-btn{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    font-family:'JetBrains Mono',monospace; font-size:11px; padding:8px 14px; border-radius:20px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
  }
  .home-link:hover,.tool-btn:hover{color:var(--gold); border-color:var(--gold)}
  .logout-btn:hover{color:var(--clay); border-color:var(--clay)}
  .login-screen{min-height:70vh; display:none; flex-direction:column; align-items:center; justify-content:center; gap:22px; text-align:center; padding:60px 20px}
  .login-screen h1{font-family:'Fraunces',serif; font-weight:500; font-size:26px; margin:0}
  .login-screen p{color:var(--ink-dim); font-size:14px; max-width:380px; margin:0; line-height:1.6}
  .login-error{display:none; color:var(--clay); font-size:13px; border:1px dashed var(--clay); border-radius:8px; padding:10px 16px; max-width:380px}
  .login-form{display:flex; flex-direction:column; gap:12px; width:100%; max-width:320px; text-align:left}
  .login-form label{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); letter-spacing:.04em; text-transform:uppercase}
  .login-form input{width:100%; background:var(--card); border:1px solid var(--line-strong); border-radius:10px; padding:12px 14px; color:var(--ink); font-family:'Inter',sans-serif; font-size:16px}
  .login-form input:focus{outline:none; border-color:var(--gold)}
  .login-submit{margin-top:6px; background:var(--gold); color:#141c16; border:none; font-family:'Inter',sans-serif; font-weight:600; font-size:14px; padding:12px 18px; border-radius:24px; cursor:pointer}
  .login-submit:hover{filter:brightness(1.08)}
  .login-submit:disabled{opacity:.6; cursor:default}
  .admin-panel{display:none; padding:28px 0 80px}
  .admin-toolbar{display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:16px}
  .admin-who{font-family:'JetBrains Mono',monospace; font-size:11.5px; color:var(--ink-dim)}
  .admin-who b{color:var(--gold); font-weight:600}
  .admin-session{color:var(--ink-dim); font-size:10.5px; margin-left:6px}
  .stats-row{display:grid; grid-template-columns:repeat(auto-fit, minmax(110px,1fr)); gap:10px; margin-bottom:18px}
  .stat-card{background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px 14px}
  .stat-card.clickable{cursor:pointer; transition:border-color .15s ease, background-color .15s ease}
  .stat-card.clickable:hover{border-color:var(--gold)}
  .stat-card.clickable:focus-visible{outline:2px solid var(--gold); outline-offset:2px}
  .stat-card.clickable.active{border-color:var(--gold); background:rgba(201,162,39,.07)}
  .stat-card.has-pending{border-color:var(--clay)}
  .stat-card.has-pending .value{color:var(--clay)}
  .stat-card .label{font-family:'JetBrains Mono',monospace; font-size:10px; color:var(--ink-dim); text-transform:uppercase; letter-spacing:.04em}
  .stat-card .value{font-family:'Fraunces',serif; font-size:22px; font-weight:500; margin-top:4px}
  .filters-row{display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:14px}
  .admin-search{flex:1 1 180px; width:auto; max-width:min(100%,340px); min-width:0; height:40px; max-height:40px; background:var(--card); border:1px solid var(--line-strong); border-radius:20px; padding:0 14px; color:var(--ink); font-family:'Inter',sans-serif; font-size:15px; line-height:40px; box-sizing:border-box}
  .admin-search::placeholder{font-size:12px; color:var(--ink-dim); opacity:1}
  .admin-search:focus{outline:none; border-color:var(--gold)}
  .filter-select{background:var(--card); border:1px solid var(--line-strong); border-radius:20px; padding:0 12px; height:40px; max-height:40px; color:var(--ink); font-family:'JetBrains Mono',monospace; font-size:11px; cursor:pointer; flex:0 0 auto}
  .filter-select:focus{outline:none; border-color:var(--gold)}
  .photo-filter-chip{display:none; align-items:center; gap:6px; background:rgba(201,162,39,.12); border:1px solid var(--gold); color:var(--gold); font-family:'JetBrains Mono',monospace; font-size:11px; padding:6px 10px; border-radius:16px; max-width:100%}
  .photo-filter-chip.open{display:inline-flex}
  .photo-filter-chip button{background:none; border:none; color:var(--gold); cursor:pointer; font-size:14px; line-height:1; padding:0 2px}
  .admin-count-line{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); margin-bottom:12px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between}
  .admin-count-line b{color:var(--ink)}
  .bulk-bar{display:none; position:sticky; bottom:12px; z-index:20; background:var(--card); border:1px solid var(--gold); border-radius:14px; padding:10px 14px; margin-top:16px; box-shadow:0 8px 28px rgba(0,0,0,.35); align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap}
  .bulk-bar.open{display:flex}
  .bulk-bar span{font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--ink)}
  .bulk-bar .bulk-actions{display:flex; gap:8px; flex-wrap:wrap}
  .bulk-del-btn{background:var(--clay); color:#fff; border:none; font-family:'JetBrains Mono',monospace; font-size:12px; padding:9px 16px; border-radius:18px; cursor:pointer}
  .bulk-clear-btn{background:none; border:1px solid var(--line-strong); color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:12px; padding:9px 14px; border-radius:18px; cursor:pointer}
  .admin-list{display:flex; flex-direction:column; gap:10px}
  .admin-item{display:grid; grid-template-columns:auto auto 1fr; grid-template-areas:"check thumb body" "actions actions actions"; gap:10px 12px; align-items:start; background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px 14px}
  .admin-item.selected{border-color:var(--gold); background:rgba(201,162,39,.06)}
  .admin-check{grid-area:check; margin-top:4px; width:18px; height:18px; accent-color:var(--gold); flex-shrink:0; cursor:pointer}
  .ph-select{grid-area:check; margin-top:4px; width:18px; height:18px; accent-color:var(--gold); flex-shrink:0; cursor:pointer}
  .admin-thumb{grid-area:thumb; width:64px; height:48px; object-fit:cover; border-radius:6px; flex-shrink:0; background:var(--bg); cursor:pointer}
  .admin-thumb-empty{border:1px dashed var(--line-strong); cursor:default}
  .admin-item-body{grid-area:body; min-width:0}
  .admin-item-head{display:flex; flex-direction:column; gap:2px; margin-bottom:4px}
  .admin-author{font-family:'Fraunces',serif; font-size:14px; font-weight:500}
  .admin-date{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim)}
  .admin-photo-name{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); margin-bottom:5px; overflow-wrap:anywhere; word-break:break-all}
  .admin-photo-name a{color:var(--teal); text-decoration:none; border-bottom:1px dotted var(--teal)}
  .admin-photo-name a:hover{color:var(--gold); border-color:var(--gold)}
  .admin-text{font-size:13.5px; line-height:1.5; color:var(--ink); white-space:pre-wrap; overflow-wrap:anywhere}
  .admin-item-actions{grid-area:actions; display:flex; flex-direction:row; gap:8px; flex-wrap:wrap}
  .admin-del-btn{background:none; border:1px solid var(--line-strong); color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:12px; padding:9px 16px; border-radius:16px; cursor:pointer; white-space:nowrap}
  .admin-del-btn:hover{color:var(--clay); border-color:var(--clay)}
  .admin-del-btn:disabled{opacity:.5; cursor:default}
  @media (min-width:700px){ .admin-item{grid-template-columns:auto auto 1fr auto; grid-template-areas:"check thumb body actions"; align-items:start} .admin-item-actions{flex-direction:column} .admin-item-head{flex-direction:row; align-items:baseline; justify-content:space-between; gap:10px} }
  .admin-empty,.admin-error{text-align:center; color:var(--ink-dim); font-size:13px; font-style:italic; padding:40px 10px}
  .load-more-btn{display:block; margin:22px auto 0; background:none; border:1px solid var(--line-strong); color:var(--ink); font-family:'JetBrains Mono',monospace; font-size:12px; padding:10px 22px; border-radius:20px; cursor:pointer}
  .load-more-btn:hover{border-color:var(--gold); color:var(--gold)}
  .load-more-btn:disabled{opacity:.5; cursor:default}
  .top-photos{margin:0 0 18px; padding:12px 14px; background:var(--card); border:1px solid var(--line); border-radius:10px}
  .top-photos h3{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); text-transform:uppercase; letter-spacing:.04em; margin:0 0 10px; font-weight:500}
  .top-photos-list{display:flex; flex-direction:column; gap:6px}
  .top-photo-row{display:flex; align-items:center; gap:10px; font-size:12.5px; cursor:pointer; padding:4px 0; border-radius:6px}
  .top-photo-row:hover{color:var(--gold)}
  .top-photo-row img{width:36px; height:28px; object-fit:cover; border-radius:4px; background:var(--bg)}
  .top-photo-row .cnt{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--gold); margin-left:auto}
  .log-section{margin-top:28px; padding-top:20px; border-top:1px solid var(--line)}
  .log-section h3{font-family:'Fraunces',serif; font-size:16px; font-weight:500; margin:0 0 10px}
  .log-list{display:flex; flex-direction:column; gap:8px}
  .log-item{font-size:12px; color:var(--ink-dim); padding:8px 10px; background:var(--card); border:1px solid var(--line); border-radius:8px; line-height:1.45}
  .log-item b{color:var(--ink); font-weight:500}
  .log-item .log-action{color:var(--clay); font-family:'JetBrains Mono',monospace; font-size:10.5px; text-transform:uppercase}
  .modal-backdrop{display:none; position:fixed; inset:0; z-index:100; background:rgba(8,14,10,.72); align-items:center; justify-content:center; padding:20px}
  .modal-backdrop.open{display:flex}
  .modal{background:var(--bg); border:1px solid var(--line-strong); border-radius:14px; width:min(420px,100%); max-height:88vh; overflow:auto; padding:20px 18px; box-shadow:0 20px 50px rgba(0,0,0,.45)}
  .modal h2{font-family:'Fraunces',serif; font-size:18px; font-weight:500; margin:0 0 12px}
  .modal-preview{background:var(--card); border:1px solid var(--line); border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:13px; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; max-height:160px; overflow:auto}
  .modal-preview .who{font-family:'Fraunces',serif; font-size:13px; margin-bottom:4px}
  .modal label{display:block; font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); text-transform:uppercase; letter-spacing:.04em; margin:10px 0 6px}
  .modal input, .modal textarea{width:100%; background:var(--card); border:1px solid var(--line-strong); border-radius:8px; padding:10px 12px; color:var(--ink); font-family:'Inter',sans-serif; font-size:16px}
  .photo-reject-modal select{width:100%; background:var(--card); border:1px solid var(--line-strong); border-radius:8px; padding:10px 12px; color:var(--ink); font-family:'Inter',sans-serif; font-size:15px}
  .modal textarea{min-height:90px; resize:vertical}
  .modal-actions{display:flex; gap:8px; justify-content:flex-end; margin-top:16px; flex-wrap:wrap}
  .modal-cancel{background:none; border:1px solid var(--line-strong); color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:12px; padding:9px 14px; border-radius:18px; cursor:pointer}
  .modal-confirm{background:var(--clay); color:#fff; border:none; font-family:'JetBrains Mono',monospace; font-size:12px; padding:9px 16px; border-radius:18px; cursor:pointer}
  .modal-confirm.save{background:var(--gold); color:#141c16}
  .modal-confirm:disabled{opacity:.55; cursor:default}
  .admin-upload-preview{display:none; align-items:center; gap:12px; margin:0 0 14px; padding:10px; background:var(--card); border:1px solid var(--line-strong); border-radius:10px}
  .admin-upload-preview img{display:block; width:96px; height:96px; object-fit:cover; border-radius:8px; background:var(--bg); border:1px solid var(--line-strong); flex-shrink:0}
  .admin-upload-preview .name{font-size:13px; font-weight:500; overflow-wrap:anywhere}
  .admin-upload-preview .meta{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); margin-top:4px}
  .admin-upload-drop{border:1px dashed var(--line-strong); border-radius:10px; padding:18px 14px; margin-bottom:14px; text-align:center; cursor:pointer; background:var(--card); transition:border-color .2s, background-color .2s}
  .admin-upload-drop:hover,.admin-upload-drop.drag-over{border-color:var(--gold); background:rgba(201,162,39,.06)}
  .admin-upload-drop svg{width:24px; height:24px; color:var(--gold); display:block; margin:0 auto 7px}
  .admin-upload-drop .drop-title{font-size:12.5px; color:var(--ink-dim)}
  .admin-upload-drop .picked{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink); margin-top:7px; overflow-wrap:anywhere}
  .modal-confirm.is-uploading{display:inline-flex; align-items:center; justify-content:center; gap:8px; min-width:150px}
  .admin-upload-loader{width:14px; height:14px; border:2px solid rgba(13,21,18,.25); border-top-color:#0d1512; border-right-color:var(--teal); border-radius:50%; animation:adminUploadSpin .85s linear infinite; flex:0 0 auto}
  @keyframes adminUploadSpin{to{transform:rotate(360deg)}}
  .modal-upload-sub{font-size:12px; color:var(--ink-dim); line-height:1.5; margin:0 0 14px}
  .admin-upload-step{display:none}
  .admin-upload-step.on{display:block}
  .admin-upload-scan{text-align:center; padding:28px 0 22px}
  .admin-scan-ring{width:48px; height:48px; margin:0 auto 14px; border:2px solid var(--line-strong); border-top-color:var(--gold); border-right-color:var(--teal); border-radius:50%; animation:adminUploadSpin .85s linear infinite}
  .admin-upload-scan .scan-title{font-family:'Fraunces',serif; font-size:17px; margin-bottom:4px}
  .admin-upload-scan .scan-file{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); overflow-wrap:anywhere; margin-bottom:8px}
  .admin-scan-steps{list-style:none; margin:12px auto 0; padding:0; text-align:left; display:inline-block}
  .admin-scan-steps li{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); padding:4px 0 4px 22px; position:relative}
  .admin-scan-steps li::before{content:'○'; position:absolute; left:3px}
  .admin-scan-steps li.on{color:var(--ink)}
  .admin-scan-steps li.on::before{content:'◐'; color:var(--gold)}
  .admin-scan-steps li.ok{color:var(--teal)}
  .admin-scan-steps li.ok::before{content:'●'; color:var(--teal)}
  .admin-scan-steps li.skip{opacity:.7}
  .admin-scan-steps li.skip::before{content:'–'}
  @media (prefers-reduced-motion:reduce){.admin-scan-ring,.admin-upload-loader{animation:none}}
  .tool-btn.active{color:var(--gold); border-color:var(--gold)}
  .modal input[type=date], .modal input[type=datetime-local]{color-scheme:dark}
  .modal .hint{font-size:11.5px; color:var(--ink-dim); margin-top:6px; line-height:1.4}
  .modal select{width:100%; background:var(--card); border:1px solid var(--line-strong); border-radius:8px; padding:10px 12px; color:var(--ink); font-family:'Inter',sans-serif; font-size:16px}
  .modal-row{display:grid; grid-template-columns:1fr 1fr; gap:10px}
  .admin-item.unpublished{opacity:.55}
  .admin-stats{display:flex; flex-wrap:wrap; gap:6px; margin-top:8px}
  .admin-chip{display:inline-flex; align-items:center; gap:5px; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); border:1px solid var(--line); border-radius:999px; padding:3px 9px; text-decoration:none; line-height:1.3}
  .admin-chip svg{width:12px; height:12px; flex-shrink:0}
  a.admin-chip.loc-yes{color:var(--teal); border-color:rgba(90,138,134,.55)}
  a.admin-chip.loc-yes:hover{color:var(--gold); border-color:var(--gold)}
  .admin-chip.kmt-ok{color:#1c7c3c; border-color:rgba(28,124,60,.5); background:rgba(28,124,60,.09)}
  .admin-chip.kmt-far{color:#c0392b; border-color:rgba(192,57,43,.5); background:rgba(192,57,43,.09)}
  .admin-chip.loc-no{opacity:.6}
  .admin-chip.attr-chip{color:var(--gold); border-color:rgba(199,154,62,.55); background:rgba(199,154,62,.09)}
  .admin-geo-callout{margin-top:8px; font-size:12px; line-height:1.45; padding:7px 10px; border-radius:8px; border:1px solid var(--line)}
  .admin-geo-callout.geo-proposed{color:var(--gold); border-color:rgba(199,154,62,.55); background:rgba(199,154,62,.08)}
  .admin-geo-callout.geo-far{color:var(--ink-dim)}
  .admin-geo-callout.geo-failed{color:var(--clay); border-color:rgba(176,94,58,.5)}
  .admin-geo-callout.geo-ok{color:var(--teal); border-color:rgba(90,138,134,.5); background:rgba(90,138,134,.08)}
/* okno „Sprawdź i zatwierdź” — odczytane dane z pliku */
.rev-grid{
  display:grid; grid-template-columns:auto 1fr; gap:4px 12px; margin:8px 0 4px;
  font-size:12px; line-height:1.45;
}
.rev-grid .k{ color:var(--ink-dim); white-space:nowrap; }
.rev-grid .v{ color:var(--ink); word-break:break-word; }
.rev-grid .sec{ grid-column:1 / -1; margin-top:8px; font-family:'JetBrains Mono',monospace;
  font-size:10.5px; letter-spacing:0.05em; text-transform:uppercase; color:var(--ink-dim); }
/* etykiety atrakcji w oknie „Sprawdź i zatwierdź” — chipy z możliwością usunięcia */
.rev-attr{ margin:12px 0 2px; }
.rev-attr > label{ display:block; font-size:12px; color:var(--ink-dim); margin-bottom:6px; }
.rev-attr-chips{ display:flex; flex-wrap:wrap; gap:6px; min-height:1px; }
.rev-attr-chips .chip{
  display:inline-flex; align-items:center; gap:6px; font-family:'JetBrains Mono',monospace;
  font-size:11px; color:var(--teal); border:1px solid rgba(90,138,134,.55);
  background:rgba(90,138,134,.08); border-radius:999px; padding:3px 6px 3px 10px; line-height:1.3;
}
.rev-attr-chips .chip-x{
  display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px;
  border:none; background:none; color:var(--teal); font-size:14px; line-height:1; cursor:pointer; border-radius:50%;
}
.rev-attr-chips .chip-x:hover{ color:var(--clay); background:rgba(176,94,58,.14); }
.rev-attr-empty{ font-size:11.5px; color:var(--ink-dim); opacity:.8; }
.rev-attr-add{ display:flex; gap:8px; margin-top:8px; }
.rev-attr-add select{ flex:1 1 auto; min-width:0; }
.rev-attr-add button{ flex:0 0 auto; }
/* etykiety atrakcji w oknie edycji zdjęcia — zwykłe checkboxy w siatce */
.ed-attr-checks{ display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:2px 16px; margin:6px 0 0; }
.ed-attr-checks .ed-attr-item{
  display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--ink); cursor:pointer; padding:3px 0;
}
.ed-attr-checks .ed-attr-item input{ width:auto; accent-color:var(--gold); flex-shrink:0; }
.ed-attr-checks .rev-attr-empty{ grid-column:1 / -1; }
  .admin-title-link{color:inherit; text-decoration:none}
  .admin-title-link:hover{color:var(--gold)}
  a.admin-thumb-link{display:block; grid-area:thumb; line-height:0}
  .admin-pub-btn,.admin-edit-btn,.admin-check-btn{background:none; border:1px solid var(--line-strong); color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:11px; padding:6px 12px; border-radius:16px; cursor:pointer}
  .admin-edit-btn:hover{color:var(--gold); border-color:var(--gold)}
  .admin-check-btn:hover{color:var(--teal); border-color:var(--teal)}
  a.admin-edit-btn{text-decoration:none; display:inline-block; text-align:center}
  .admin-pub-btn:hover{color:var(--teal); border-color:var(--teal)}
  .cat-row{display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid var(--line); font-size:13px}
  .cat-row span.cnt{margin-left:auto; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim)}
  .toast{position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px); background:var(--card); border:1px solid var(--gold); color:var(--ink); font-family:'JetBrains Mono',monospace; font-size:12px; padding:10px 18px; border-radius:20px; z-index:120; opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; box-shadow:0 8px 24px rgba(0,0,0,.35); max-width:90vw; text-align:center}
  .toast.show{opacity:1; transform:translateX(-50%) translateY(0)}
  @media (max-width:640px){
    .admin-toolbar{flex-direction:column; align-items:flex-start; gap:10px}
    .filters-row{flex-direction:row; flex-wrap:wrap; align-items:center; gap:8px}
    .admin-search{flex:1 1 100%; width:100%; max-width:100%; height:38px; max-height:38px; line-height:38px; font-size:16px; padding:0 12px}
    .admin-search::placeholder{font-size:11.5px}
    .filter-select{flex:1 1 calc(50% - 4px); min-width:0; max-width:100%; height:38px; max-height:38px}
    .stats-row{grid-template-columns:repeat(3, 1fr); gap:8px}
    .stat-card{padding:10px 8px}
    .stat-card .value{font-size:18px}
    .header-actions{width:100%; justify-content:flex-start}
  }

  /* ---------- tryb jasny ---------- */
  html[data-theme="light"]{
    color-scheme:light;
    --bg:#f5f1e6; --bg-soft:#ece5d2; --bg-alt:#ece5d2; --card:#ffffff; --ink:#1c2620; --ink-dim:#5c6b60;
    --gold:#a9791f; --clay:#a65a3c; --teal:#3f6d69;
    --line:rgba(28,38,32,.10); --line-strong:rgba(28,38,32,.22);
  }
  html[data-theme="light"] .modal-backdrop{background:rgba(28,38,32,.45)}
  html[data-theme="light"] .modal{box-shadow:0 20px 50px rgba(28,38,32,.25)}
  html[data-theme="light"] .modal input[type=date], html[data-theme="light"] .modal input[type=datetime-local]{color-scheme:light}
  html[data-theme="light"] .bulk-bar{box-shadow:0 8px 28px rgba(28,38,32,.18)}
  html[data-theme="light"] .admin-item.selected, html[data-theme="light"] .stat-card.clickable.active{background:rgba(169,121,31,.09)}
  html[data-theme="light"] .photo-filter-chip{background:rgba(169,121,31,.12)}
  body{transition:background-color .25s ease, color .25s ease}
  .theme-btn{padding:8px 11px}
  .theme-btn svg{width:14px; height:14px}
  .theme-btn .icon-sun{display:none}
  html[data-theme="light"] .theme-btn .icon-moon{display:none}
  html[data-theme="light"] .theme-btn .icon-sun{display:block}

  /* ---------- miniatury zdjęć: prostokąt, bez przycinania ---------- */
  .admin-thumb.admin-thumb-photo{
    width:132px; height:88px; object-fit:contain; background:var(--bg-soft);
    border:1px solid var(--line); border-radius:8px;
  }
  a.admin-thumb-link .admin-thumb-photo{display:block}
  @media (max-width:640px){ .admin-thumb.admin-thumb-photo{width:96px; height:68px} }

  /* ---------- powiadomienia: alerty + konfiguracja (ta sama skóra co notatnik) ---------- */
  .notify-modal{ width:min(820px,100%); display:flex; flex-direction:column; max-height:88vh }
  .notify-tabs{ display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap }
  .notify-tab{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    border-radius:20px; padding:6px 14px; cursor:pointer;
    font-family:'JetBrains Mono',monospace; font-size:11px; letter-spacing:.04em;
    text-transform:uppercase;
  }
  .notify-tab:hover{ border-color:var(--gold); color:var(--gold) }
  .notify-tab.is-on{ border-color:var(--gold); color:var(--gold); background:rgba(201,162,39,0.08) }
  .notify-pane[hidden]{ display:none }
  .notify-alert{
    display:flex; gap:11px; align-items:flex-start;
    border:1px solid var(--line); border-radius:9px; padding:10px 12px;
    background:var(--card); margin-bottom:7px;
  }
  .notify-alert.is-new{ border-color:rgba(201,162,39,0.42); background:rgba(201,162,39,0.06) }
  .notify-alert-ico{
    width:32px; height:32px; border-radius:50%; flex-shrink:0;
    border:1px solid var(--line-strong); background:var(--bg-alt);
    display:flex; align-items:center; justify-content:center; color:var(--gold);
  }
  .notify-alert-ico svg{ width:15px; height:15px }
  .notify-alert-body{ flex:1; min-width:0 }
  .notify-alert-title{ font-size:13px; font-weight:600; margin-bottom:2px }
  .notify-alert-text{ font-size:12.5px; color:var(--ink-dim); line-height:1.5; overflow-wrap:anywhere }
  .notify-alert-when{
    font-family:'JetBrains Mono',monospace; font-size:10px; color:var(--ink-dim); margin-top:4px;
    display:flex; gap:9px; flex-wrap:wrap;
  }
  .notify-alert-when a{ color:var(--teal); text-decoration:none }
  .notify-alert-when a:hover{ color:var(--gold) }

  /* wiersz konfiguracji jednego typu */
  .notify-cfg{
    display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap;
    border:1px solid var(--line); border-radius:9px; padding:11px 12px;
    background:var(--card); margin-bottom:7px;
  }
  .notify-cfg.is-off{ opacity:.72 }
  .notify-cfg-main{ flex:1; min-width:210px }
  .notify-cfg-main input[type=text]{
    width:100%; background:var(--bg); color:var(--ink);
    border:1px solid var(--line-strong); border-radius:7px; padding:6px 10px;
    font-family:'Inter',sans-serif; font-size:13.5px;
  }
  .notify-cfg-main input[type=text]:focus{ outline:none; border-color:var(--gold) }
  .notify-cfg-desc{ font-size:12px; color:var(--ink-dim); line-height:1.5; margin-top:5px }
  .notify-cfg-targets{ display:flex; gap:11px; flex-wrap:wrap; margin-top:7px; align-items:center }
  .notify-chk{
    display:inline-flex; align-items:center; gap:5px; cursor:pointer;
    font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim);
  }
  .notify-chk input{ accent-color:var(--gold); width:14px; height:14px; cursor:pointer }
  .notify-chk:hover{ color:var(--gold) }
  .notify-cfg-save{
    align-self:center; flex-shrink:0;
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    border-radius:7px; padding:6px 13px; cursor:pointer;
    font-family:'JetBrains Mono',monospace; font-size:11px;
  }
  .notify-cfg-save:hover{ border-color:var(--gold); color:var(--gold) }
  .notify-cfg-count{
    font-family:'JetBrains Mono',monospace; font-size:10px; color:var(--ink-dim);
    border:1px solid var(--line); border-radius:5px; padding:1px 6px; white-space:nowrap;
  }
  .notify-broadcast{ display:flex; gap:9px; margin-top:12px; align-items:stretch }
  .notify-broadcast textarea{
    flex:1; min-height:60px; max-height:160px; resize:vertical;
    background:var(--bg); color:var(--ink); border:1px solid var(--line-strong);
    border-radius:8px; padding:9px 11px; font-family:'Inter',sans-serif; font-size:13.5px; line-height:1.5;
  }
  .notify-broadcast textarea:focus{ outline:none; border-color:var(--gold) }
  .notify-broadcast button{ align-self:flex-end; flex-shrink:0 }

  /* ---------- notatnik (lista zadań) ---------- */
  .tool-btn .badge{
    min-width:17px; height:17px; padding:0 5px; border-radius:9px; background:var(--gold); color:#141c16;
    font-size:10px; font-weight:600; display:none; align-items:center; justify-content:center;
  }
  .tool-btn .badge.show{display:inline-flex}
  /* Liczniki list moderacyjnych pozostają widoczne, ale bez żółtego wyróżnienia */
  #blockedBtn .badge,#wordsBtn .badge,#notesBtn .badge,#tabProjects .badge{background:var(--line);color:var(--ink-dim);border:1px solid var(--line-strong);font-weight:500}

  .notes-modal{width:min(480px,100%); display:flex; flex-direction:column; max-height:88vh}
  /* laptop / monitor: duże okno notatnika */
  @media (min-width:900px){
    .notes-modal{width:min(820px,92vw); height:min(760px,86vh); max-height:86vh; padding:26px 28px}
    .notes-modal .notes-head h2{font-size:24px}
    .notes-modal .notes-progress{font-size:12.5px}
    .notes-modal .notes-add input{font-size:17px; padding:13px 15px}
    .notes-modal .notes-add .modal-confirm{font-size:14px; padding:0 24px}
    .notes-modal .notes-list{flex:1; max-height:none; gap:8px}
    .notes-modal .note-item{padding:13px 15px; gap:14px}
    .notes-modal .note-item input[type=checkbox]{width:22px;height:22px}
    .notes-modal .note-text, .notes-modal .note-edit-input{font-size:16px !important; line-height:1.5}
    .notes-modal .note-btn{font-size:18px; padding:5px 8px}
    .notes-modal .notes-empty{font-size:15px; padding:40px 10px}
  }
  .notes-head{display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin-bottom:12px}
  .notes-head h2{margin:0}
  .notes-progress{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim)}
  .notes-add{display:flex; gap:8px; margin-bottom:12px}
  .notes-add input{flex:1; min-width:0}
  .notes-list{display:flex; flex-direction:column; gap:6px; overflow:auto; min-height:60px; max-height:46vh; padding-right:2px}
  .note-item{display:flex; align-items:flex-start; gap:10px; background:var(--card); border:1px solid var(--line); border-radius:10px; padding:9px 10px}
  .note-item-controls{display:flex;flex-direction:column;align-items:center;gap:9px;flex:0 0 24px;padding-top:1px}
  .note-item input[type=checkbox]{appearance:none;-webkit-appearance:none;width:20px;height:20px;margin:0;flex-shrink:0;cursor:pointer;display:grid;place-content:center;border:1.7px solid var(--ink-dim);border-radius:6px;background:var(--bg);transition:background .16s,border-color .16s,box-shadow .16s,transform .16s}
  .note-item input[type=checkbox]::before{content:"";width:9px;height:5px;border:solid #fff;border-width:0 0 2px 2px;transform:rotate(-45deg) scale(0);transform-origin:center;transition:transform .14s ease}
  .note-item input[type=checkbox]:checked{background:#16a34a;border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.13)}
  .note-item input[type=checkbox]:checked::before{transform:rotate(-45deg) scale(1)}
  .note-item input[type=checkbox]:hover{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.10)}
  .note-item input[type=checkbox]:focus-visible{outline:2px solid #16a34a;outline-offset:3px}
  .note-item input[type=checkbox]:active{transform:scale(.92)}
  .note-doing-btn{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;margin:0;border:1px solid transparent;border-radius:6px;background:transparent;color:var(--ink-dim);font-size:14px;line-height:1;cursor:pointer}
  .note-doing-btn.active{color:#16a34a;background:rgba(22,163,74,.10);border-color:rgba(22,163,74,.24)}
  .note-doing-btn:hover{color:#16a34a;background:rgba(22,163,74,.10)}
  .notes-modal .note-item-controls{flex-basis:25px;gap:11px}
  .notes-modal .note-doing-btn{width:25px;height:25px;flex-basis:25px;font-size:16px}
  .note-text{flex:1; min-width:0; font-size:14px; line-height:1.45; overflow-wrap:anywhere; cursor:text}
  .note-item.done .note-text{text-decoration:line-through; color:var(--ink-dim)}
  .note-edit-input{flex:1; min-width:0; padding:5px 8px !important; font-size:14px !important}
  .note-btn{background:none; border:none; color:var(--ink-dim); cursor:pointer; font-size:15px; line-height:1; padding:3px 5px; border-radius:6px; flex-shrink:0}
  .note-btn:hover{color:var(--gold)}
  .note-btn.del:hover{color:var(--clay)}
  .notes-empty{color:var(--ink-dim); font-size:13px; font-style:italic; text-align:center; padding:18px 6px}
  .notes-sep{font-family:'JetBrains Mono',monospace; font-size:10px; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-dim); margin:8px 0 2px}
  .notes-list .note-item{cursor:pointer;transition:border-color .15s,background .15s}
  .notes-list .note-item:hover{border-color:var(--gold);background:var(--bg-soft)}
  .note-content{flex:1;min-width:0;cursor:pointer}
  .note-preview{font-size:13.5px;line-height:1.5;color:var(--ink);white-space:pre-wrap;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
  .note-meta{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--ink-dim);margin-top:5px}
  .note-detail-backdrop{position:fixed;inset:0;z-index:1200;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.72)}
  .note-detail-backdrop.open{display:flex}
  .note-detail-modal{width:min(1100px,96vw);max-height:98vh;display:flex;flex-direction:column;gap:12px}
  .note-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
  .note-detail-head-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
  .note-detail-doing{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:0 11px;border:1px solid var(--line-strong);border-radius:999px;background:transparent;color:var(--ink-dim);font:500 11px 'Inter',sans-serif;white-space:nowrap;cursor:pointer;transition:background .16s,border-color .16s,color .16s}
  .note-detail-doing:hover{background:rgba(22,163,74,.05);border-color:rgba(22,163,74,.32);color:#478a5c}
  .note-detail-doing.active{background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.28);color:#4b9461;font-weight:600;box-shadow:none}
  .note-detail-doing:disabled{opacity:.55;cursor:default;transform:none}
  .note-detail-doing .dot{width:8px;height:8px;border:1.5px solid currentColor;border-radius:50%;background:transparent;box-shadow:none}
  .note-detail-doing.active .dot{border-color:currentColor;background:currentColor}
  .note-detail-body{overflow:auto;min-height:100px;max-height:72vh;padding:14px;border:1px solid var(--line);border-radius:9px;background:var(--bg);color:var(--ink);font-size:14px;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere}
  .note-detail-body a{color:var(--gold);text-decoration:underline;overflow-wrap:anywhere}
  .note-detail-editor{width:100%;min-height:76vh;max-height:84vh;resize:vertical;padding:16px;border:1px solid var(--line-strong);border-radius:9px;background:var(--bg);color:var(--ink);font:15px/1.7 'Inter',sans-serif}
  .note-detail-editor:focus{outline:none;border-color:var(--gold)}
  .note-detail-actions{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
  .note-detail-left-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .note-detail-done{position:relative;display:inline-flex;align-items:center;gap:10px;min-height:44px;padding:6px 13px 6px 9px;border:1px solid var(--line-strong);border-radius:12px;background:linear-gradient(135deg,rgba(255,255,255,.035),transparent 75%),var(--card);color:var(--ink);cursor:pointer;user-select:none;transition:transform .18s,border-color .18s,background .18s,box-shadow .18s}
  .note-detail-done:hover{transform:translateY(-1px);border-color:rgba(22,163,74,.45);box-shadow:0 5px 16px rgba(22,163,74,.08)}
  .note-detail-done:active{transform:translateY(0) scale(.985)}
  .note-detail-done input{position:absolute;width:1px;height:1px;left:12px;top:50%;margin:0;opacity:0;cursor:pointer}
  .note-detail-done input:focus-visible ~ .done-copy{outline:2px solid var(--gold);outline-offset:4px;border-radius:3px}
  .note-detail-done .done-copy{font-size:12px;font-weight:600;white-space:nowrap}
  .note-detail-done .done-hint{margin-left:2px;padding:4px 7px;border:1px solid var(--line);border-radius:999px;color:var(--ink-dim);font:9px 'JetBrains Mono',monospace;white-space:nowrap;transition:background .18s,color .18s,border-color .18s}
  .note-detail-done.is-done{border-color:rgba(22,163,74,.28);background:linear-gradient(120deg,rgba(22,163,74,.1),rgba(90,138,134,.06)),var(--card);color:#55a66e}
  .note-detail-done.is-done .done-hint{border-color:rgba(22,163,74,.2);background:rgba(22,163,74,.09);color:#55a66e}
  .note-detail-done.just-done{animation:noteDonePop .34s cubic-bezier(.2,.8,.2,1)}
  @keyframes noteDonePop{45%{transform:scale(1.035)}}
  @media(prefers-reduced-motion:reduce){.note-detail-done,.note-detail-done *{transition:none!important;animation:none!important}}
  .notes-add textarea{flex:1;min-width:0;min-height:72px;padding:10px 12px;border:1px solid var(--line-strong);border-radius:8px;background:var(--bg);color:var(--ink);font:14px/1.5 'Inter',sans-serif}
  .notes-add textarea:focus{outline:none;border-color:var(--gold)}
  @media(max-width:600px){.note-detail-backdrop{padding:6px}.note-detail-modal{width:100%;max-height:99vh}.note-detail-body,.note-detail-editor{max-height:84vh}.note-detail-editor{min-height:76vh}}


  /* ---------- projekty robocze (metasystem: zadania + prompty) ---------- */
  .proj-layout{display:grid; grid-template-columns:260px minmax(0,1fr); gap:16px; align-items:start}
  .proj-side{background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px}
  .proj-side-head{display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px}
  .proj-side-head h3{margin:0; font-size:14px}
  .proj-list{display:flex; flex-direction:column; gap:4px}
  .proj-item{display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 10px; border-radius:9px; cursor:pointer; border:1px solid transparent}
  .proj-item:hover{background:rgba(255,255,255,.04)}
  .proj-item.active{background:rgba(255,255,255,.06); border-color:var(--line)}
  .proj-item-name{font-size:13.5px; overflow-wrap:anywhere}
  .proj-item-count{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); flex-shrink:0}
  .proj-item-empty{color:var(--ink-dim); font-style:italic; font-size:12.5px; padding:8px}
  .proj-side-form{display:flex; flex-direction:column; gap:8px; margin-top:10px; border-top:1px solid var(--line); padding-top:10px}
  .proj-form-actions{display:flex; gap:8px; justify-content:flex-end}
  .proj-main{min-width:0}
  .proj-detail-head{display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px}
  .proj-detail-head h2{margin:0 0 4px}
  .proj-desc{color:var(--ink-dim); font-size:13px; line-height:1.55; margin:0 0 8px; max-width:75ch}
  .proj-detail-actions{display:flex; gap:8px; flex-shrink:0}
  .proj-status{display:inline-block; font-family:'JetBrains Mono',monospace; font-size:10px; letter-spacing:.05em; text-transform:uppercase; padding:2px 8px; border-radius:20px; border:1px solid var(--line); color:var(--ink-dim)}
  .proj-status-active{color:var(--gold); border-color:rgba(212,175,55,.4)}
  .proj-status-paused{color:var(--clay); border-color:rgba(166,90,60,.45)}
  .proj-tasks{display:flex; flex-direction:column; gap:12px; margin-top:14px}
  .proj-task{background:var(--card); border:1px solid var(--line); border-radius:12px; padding:12px 14px}
  .proj-task.done{opacity:.66}
  .proj-task.done .proj-task-title{text-decoration:line-through; color:var(--ink-dim)}
  .proj-task-head{display:flex; align-items:flex-start; gap:10px}
  .proj-task-head input[type=checkbox]{width:18px; height:18px; margin-top:2px; accent-color:var(--gold); flex-shrink:0; cursor:pointer}
  .proj-task-titles{flex:1; min-width:0; display:flex; flex-direction:column; gap:4px}
  .proj-task-title{font-weight:600; font-size:14.5px; cursor:text; overflow-wrap:anywhere}
  .proj-task-desc{font-size:12.5px; color:var(--ink-dim); line-height:1.5}
  .proj-task-tools{display:flex; gap:2px; flex-shrink:0}
  .proj-task-edit-title,.proj-task-edit-desc{width:100%; margin-bottom:8px}
  .proj-task-edit-row{display:flex; gap:14px; align-items:center; margin-bottom:10px; flex-wrap:wrap}
  .proj-task-edit-row label{display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--ink-dim)}
  .proj-prompts{display:flex; flex-direction:column; gap:10px; margin-top:12px; padding-top:12px; border-top:1px dashed var(--line)}
  .proj-prompt{background:rgba(0,0,0,.16); border:1px solid var(--line); border-radius:10px; padding:10px}
  .proj-prompt.draft{border-style:dashed; border-color:var(--gold)}
  .proj-prompt-head{display:flex; align-items:center; gap:8px; margin-bottom:8px}
  .proj-prompt-label{flex:1; min-width:0}
  .proj-prompt-tools{display:flex; gap:6px; flex-shrink:0}
  .proj-prompt-body{width:100%; font-family:'JetBrains Mono',monospace; font-size:12.5px; line-height:1.5; resize:vertical; min-height:130px}
  .proj-prompt-add{align-self:flex-start; background:none; border:1px dashed var(--line); color:var(--ink-dim); border-radius:8px; padding:5px 12px; cursor:pointer; font-size:12.5px}
  .proj-prompt-add:hover{color:var(--gold); border-color:var(--gold)}
  @media (max-width:820px){ .proj-layout{grid-template-columns:1fr} }

  /* ---------- zablokowane nazwy (ta sama skóra co notatnik) ---------- */
  .notes-help{font-size:12.5px; color:var(--ink-dim); line-height:1.6; margin:0 0 12px}
  .blocked-modal{width:min(560px,100%); display:flex; flex-direction:column; max-height:88vh}
  .blocked-modal .notes-list{max-height:52vh}
  .blocked-inuse{
    font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--clay);
    border:1px solid rgba(166,90,60,.45); border-radius:5px; padding:1px 6px; flex-shrink:0;
  }

  /* ---------- zgłoszenia komentarzy (ta sama skóra co notatnik) ---------- */
  .reports-modal{width:min(720px,100%); display:flex; flex-direction:column; max-height:88vh}
  .reports-modal .notes-list{max-height:58vh}
  .report-item{
    display:flex; flex-direction:column; gap:6px; min-width:0;
    border:1px solid var(--line); border-radius:8px; padding:10px 11px;
  }
  .report-head{ display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; }
  .report-reason{
    font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--clay);
    border:1px solid rgba(166,90,60,.45); border-radius:5px; padding:1px 7px;
  }
  .report-when{ font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); }
  .report-by{ font-size:11.5px; color:var(--ink-dim); }
  .report-text{
    font-size:13px; color:var(--ink); line-height:1.5; overflow-wrap:anywhere;
    white-space:pre-wrap; word-break:break-word;
  }
  .report-text.is-missing{ color:var(--ink-dim); font-style:italic; }
  .report-note{
    font-size:12px; color:var(--ink-dim); line-height:1.45;
    border-left:2px solid var(--line-strong); padding-left:8px; overflow-wrap:anywhere;
  }
  .report-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:2px; }
  .report-actions button, .report-actions a{
    background:none; border:1px solid var(--line-strong); color:var(--ink-dim);
    border-radius:6px; padding:4px 11px; cursor:pointer; text-decoration:none;
    font-family:'JetBrains Mono',monospace; font-size:11px; display:inline-block;
  }
  .report-actions button:hover, .report-actions a:hover{ color:var(--gold); border-color:var(--gold); }
  .report-actions .danger:hover{ color:var(--clay); border-color:var(--clay); }
  .report-actions button:disabled{ opacity:.5; cursor:default; }
  .reports-pane[hidden]{ display:none; }
  .notify-tab .tab-count{ margin-left:6px; font-family:'JetBrains Mono',monospace; font-size:11px; opacity:.75; }
  .report-photo{ display:flex; gap:10px; align-items:flex-start; }
  .report-thumb{ flex:0 0 auto; display:block; }
  .report-thumb img{
    display:block; width:64px; height:64px; object-fit:cover;
    border-radius:6px; border:1px solid var(--line);
  }
  .report-sub{ font-size:11.5px; color:var(--ink-dim); }

  /* ---------- znaczniki modyfikacji, odpowiedzi, polubienia ---------- */
  .edit-chip .dot{width:8px; height:8px; border-radius:50%; background:var(--ink-dim); flex-shrink:0}
  .edit-chip.st-original .dot{background:#4fae70}
  .edit-chip.st-suspect .dot{background:#d99a2b}
  .edit-chip.st-edited .dot{background:#d0573f}
  .edit-chip{cursor:help}
  .admin-reply-badge{display:inline-flex; align-items:center; gap:4px; font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--teal); margin-bottom:4px}
  .admin-likes{display:inline-flex; align-items:center; gap:4px; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--clay); margin-top:6px}
  .admin-item.is-reply{margin-left:22px; border-left:3px solid var(--teal)}
  .modal .edit-auto-note{font-size:11.5px; color:var(--ink-dim); line-height:1.45; margin-top:6px; padding:8px 10px; border:1px dashed var(--line-strong); border-radius:8px}
  .cat-row{flex-wrap:wrap}
  .cat-row .cat-name{min-width:0; overflow-wrap:anywhere}
  .cat-row .cat-branch{color:var(--ink-dim); margin-right:2px}
  .cat-row .cnt small{color:var(--ink-dim); opacity:.8}
  .cat-row .admin-edit-btn, .cat-row .admin-del-btn{padding:5px 10px; font-size:11px}

  /* ============================ sekcja Użytkownicy ============================ */
  .modal-wide{width:min(880px, 94vw); max-height:86vh; overflow:auto}
  .admin-user-head{display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:14px}
  .admin-user-head h2{margin:0 0 4px}
  .modal-close-x{font-size:22px; line-height:1; padding:4px 10px; border-color:transparent}
  .usr-main{display:flex; align-items:center; gap:12px; min-width:0}
  .user-admin-item{min-width:0}
  .usr-avatar{flex:none; width:38px; height:38px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:var(--gold); color:#101c16; font-family:'Fraunces',serif; font-weight:600; font-size:17px}
  .usr-avatar-photo{flex:none; width:38px; height:38px; display:block; border-radius:50%; object-fit:cover; background:var(--gold)}
  .usr-info{min-width:0}
  .usr-name{font-family:'Fraunces',serif; font-weight:500; font-size:16px; display:flex; align-items:center; gap:8px; flex-wrap:wrap}
  .usr-atsign{font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--ink-dim)}
  .usr-rank-chip{display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-family:'JetBrains Mono',monospace; font-size:10.5px; letter-spacing:.02em; white-space:nowrap}
  .usr-ban-chip{display:inline-flex; padding:2px 8px; border-radius:999px; font-family:'JetBrains Mono',monospace; font-size:10px; background:rgba(208,87,63,.16); color:#d0573f; border:1px solid rgba(208,87,63,.35)}
  .banned-chip{display:inline-flex; padding:2px 9px; border-radius:999px; font-family:'JetBrains Mono',monospace; font-size:10.5px; background:rgba(208,87,63,.16); color:#d0573f; border:1px solid rgba(208,87,63,.35); margin-left:8px}
  .admin-user-panels{display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:16px}
  .admin-user-panel h3{font-family:'JetBrains Mono',monospace; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-dim); border-top:1px solid var(--line); padding-top:12px; margin:16px 0 8px}
  .admin-user-panel h3:first-child{margin-top:0; border-top:none; padding-top:0}
  .admin-user-panel label{display:block; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); margin:10px 0 4px}
  .admin-user-panel input[type=text], .admin-user-panel input[type=email], .admin-user-panel input[type=password], .admin-user-panel input[type=number], .admin-user-panel select{width:100%; background:var(--bg); border:1px solid var(--line); border-radius:8px; color:var(--ink); padding:8px 10px; font-size:13px}
  .admin-user-panel input:focus{outline:none; border-color:var(--gold)}
  .row-inline{display:flex; gap:8px; align-items:center}
  .admin-points-history{max-height:300px; overflow:auto; margin-top:6px}
  .ph-row{display:flex; align-items:baseline; gap:8px; padding:6px 0; border-bottom:1px solid var(--line); font-size:12px; flex-wrap:wrap}
  .ph-date{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); white-space:nowrap}
  .ph-delta{font-family:'JetBrains Mono',monospace; font-weight:500; white-space:nowrap}
  .ph-plus{color:var(--teal)}
  .ph-minus{color:#d0573f}
  .ph-text{min-width:0; overflow-wrap:anywhere; flex:1}
  .ph-after{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); white-space:nowrap}
  .rr-row{display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--line); flex-wrap:wrap}
  .rr-row input{background:var(--bg); border:1px solid var(--line); border-radius:8px; color:var(--ink); padding:8px 10px; font-size:13px}
  .rr-name{flex:1; min-width:140px}
  .rr-minwrap{display:flex; align-items:center; gap:6px; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim)}
  .rr-min{width:90px; text-align:right}
  .rr-users{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); white-space:nowrap}
  .rr-special{display:inline-flex; align-items:center; gap:5px; font-size:11px; color:var(--ink-dim); cursor:pointer; white-space:nowrap; text-transform:none!important}
  .rr-special input{width:auto; padding:0; accent-color:var(--gold)}
  .rr-special .sp-star{color:var(--gold)}
  #ranksEditor > .tool-btn{width:100%; margin-top:10px}
  .admin-user-panel .tool-btn.danger{color:#d0573f; border-color:rgba(208,87,63,.5)}
  .admin-user-panel .tool-btn.danger:hover{color:var(--bg); background:#d0573f; border-color:#d0573f}
  .stat-card.static{cursor:default}
  @media (max-width:760px){
    .admin-user-panels{grid-template-columns:1fr}
    .usr-name{flex-direction:column; align-items:flex-start; gap:4px}
    .usr-rank-chip{align-self:flex-start}
  }
  @media (max-width:760px){
    .user-admin-item{grid-template-columns:minmax(0,1fr); grid-template-areas:"user" "body" "actions"; gap:8px; min-width:0}
    .user-admin-item > span:first-child{display:none}
    .user-admin-item .usr-main{grid-area:user; width:100%; min-width:0}
    .user-admin-item .usr-info{flex:1; min-width:0; max-width:100%}
    .user-admin-item .usr-name{min-width:0; max-width:100%; overflow-wrap:anywhere; word-break:break-word}
    .user-admin-item .usr-atsign{min-width:0; max-width:100%; white-space:normal; overflow-wrap:anywhere; word-break:break-word}
    .user-admin-item .admin-item-body{grid-area:body; width:100%; min-width:0}
    .user-admin-item .admin-stats{width:100%; min-width:0; max-width:100%}
    .user-admin-item .usr-rank-chip{max-width:100%; white-space:normal; overflow-wrap:anywhere}
    .user-admin-item .admin-item-actions{grid-area:actions; min-width:0}
  }

  /* ============================ sekcja Statystyki ruchu ============================ */
  .stat-card .tr-delta{font-family:'JetBrains Mono',monospace; font-size:10.5px; margin-top:3px; color:var(--ink-dim)}
  .tr-delta .up{color:#5cae7d}
  .tr-delta .down{color:#d0573f}
  .tr-card{background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin-bottom:14px}
  .tr-card h3{font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--ink-dim); text-transform:uppercase; letter-spacing:.04em; margin:0 0 12px; font-weight:500}
  .tr-grid{display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; align-items:start}
  .tr-grid .tr-card{margin-bottom:0}
  .set-banner{background:var(--card); border:1px solid var(--line); border-left:3px solid var(--gold); border-radius:10px; padding:12px 16px; margin-bottom:14px; font-size:13px; line-height:1.6; color:var(--ink-dim)}
  .set-banner b{color:var(--ink)}
  .set-subtabs{display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px}
  .set-subtabs .tool-btn.active{background:var(--gold); color:#101c16; border-color:var(--gold)}
  .set-intro{margin:0 0 14px; font-size:13px; line-height:1.6; color:var(--ink-dim); max-width:760px}
  .set-item{padding:10px 0; border-top:1px solid var(--line)}
  .set-item:first-of-type{border-top:0; padding-top:0}
  .set-item-top{display:flex; align-items:baseline; justify-content:space-between; gap:10px; flex-wrap:wrap}
  .set-item-top b{font-size:13.5px; font-weight:600}
  .set-desc{margin-top:4px; font-size:12.5px; line-height:1.6; color:var(--ink-dim)}
  .set-item .tool-btn{margin-top:8px}
  .set-badge{font-family:'JetBrains Mono',monospace; font-size:10.5px; padding:2px 8px; border-radius:999px; border:1px solid var(--line-strong); color:var(--ink-dim); white-space:nowrap}
  .set-badge.set-hot{color:var(--gold); border-color:var(--gold)}
  .set-badge.set-exists{color:var(--teal); border-color:var(--teal)}
  .tx-list{margin-bottom:18px}
  .tx-grid{display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:14px; align-items:start}
  .tx-grid .tr-card{margin-bottom:0}
  a.tool-btn{text-decoration:none; display:inline-flex; align-items:center}
  .tx-field{padding:12px 0; border-top:1px solid var(--line)}
  .tx-field:first-of-type{border-top:0; padding-top:0}
  .tx-top{display:flex; align-items:baseline; justify-content:space-between; gap:10px}
  .tx-top b{font-size:13.5px; font-weight:600}
  .tx-hint{margin:3px 0 6px; font-size:12px; line-height:1.5; color:var(--ink-dim)}
  .tx-field input[type=text], .tx-field textarea{width:100%; background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:8px 10px; color:var(--ink); font:inherit; font-size:14px; resize:vertical}
  .tx-field.dirty input[type=text], .tx-field.dirty textarea{border-color:var(--gold)}
  .tx-meta{display:flex; flex-wrap:wrap; gap:4px 12px; margin-top:5px; font-size:11.5px; color:var(--ink-dim)}
  .tx-count.over{color:#d0573f}
  .tx-default{margin-top:4px; font-size:11.5px; color:var(--ink-dim); word-break:break-word}
  .tx-err{margin-top:4px; font-size:12px; color:#d0573f}
  .tx-err:empty{display:none}
  .tx-actions{display:flex; gap:8px; margin-top:8px}
  .tx-actions .tool-btn:disabled{opacity:.45; cursor:default}
  .tx-flag{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim)}
  .tx-flag.tx-custom{color:var(--teal)}
  .tx-flag.tx-unsaved{color:var(--gold)}
  .tx-hist:empty{display:none}
  .tx-hist{margin-top:8px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:12px; color:var(--ink-dim)}
  .tx-hist-row{display:grid; grid-template-columns:1fr auto; gap:2px 10px; padding:6px 0; border-top:1px solid var(--line)}
  .tx-hist-row:first-child{border-top:0; padding-top:0}
  .tx-hist-meta{grid-column:1 / -1; font-size:11px}
  .tx-hist-val{color:var(--ink); word-break:break-word}

  .tr-chart{display:flex; align-items:flex-end; gap:2px; height:170px; border-bottom:1px solid var(--line); padding-bottom:2px}
  .tr-chart .bar{flex:1 1 0; min-width:2px; background:var(--teal, #5a8a86); border-radius:2px 2px 0 0; position:relative; opacity:.85}
  .tr-chart .bar:hover{opacity:1}
  .tr-chart .bar .uv{position:absolute; bottom:0; left:0; right:0; background:var(--gold); border-radius:2px 2px 0 0}
  .tr-chart-axis{display:flex; justify-content:space-between; color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:10px; margin-top:6px}
  .tr-legend{display:flex; gap:14px; flex-wrap:wrap; color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:10.5px; margin-top:6px}
  .tr-legend i{display:inline-block; width:10px; height:10px; border-radius:2px; margin-right:5px; vertical-align:middle}
  .tr-table-wrap{display:block; width:100%; max-width:100%; overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch}
  .tr-table{width:100%; max-width:100%; border-collapse:collapse; font-size:12.5px}
  .tr-table-wrap .tr-table{min-width:560px}
  .tr-table th,.tr-table td{overflow-wrap:anywhere}
  .tr-table td:first-child,.tr-table th:first-child{word-break:break-word}
  .tr-card{min-width:0}
  .tr-table th{text-align:left; color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:10px; text-transform:uppercase; letter-spacing:.04em; font-weight:500; padding:4px 6px; border-bottom:1px solid var(--line)}
  .tr-table th.num,.tr-table td.num{text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap}
  .tr-table td{padding:5px 6px; border-bottom:1px solid var(--line)}
  .tr-table tr:last-child td{border-bottom:none}
  .tr-bar{display:inline-block; height:7px; border-radius:4px; background:var(--teal, #5a8a86); vertical-align:middle; min-width:2px}
  .tr-muted{color:var(--ink-dim); font-size:12.5px; font-style:italic}
  .tr-hours{display:grid; gap:2px}
  .tr-hour{display:grid; grid-template-columns:44px 1fr 46px; align-items:center; gap:8px; font-size:11.5px}
  .tr-hour .m{font-family:'JetBrains Mono',monospace; color:var(--ink-dim)}
  .tr-hour .b{background:var(--line); border-radius:3px; height:8px; overflow:hidden}
  .tr-hour .b span{display:block; height:100%; background:var(--gold)}
  .tr-hour .n{font-family:'JetBrains Mono',monospace; text-align:right; color:var(--ink-dim)}

  /* ---- bezpieczeństwo ---- */
  /* Układ sekcji bezpieczeństwa: bez wychodzenia kart poza kontener */
  #securityView{min-width:0;width:100%;overflow:hidden}
  #securityView *{box-sizing:border-box;min-width:0}
  #securityView .filters-row{align-items:center}
  #securityView .sec-status-grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))}
  #securityView #secKpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,155px),1fr));gap:10px}
  #securityView .sec-layout{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;align-items:start;width:100%}
  #securityView .sec-layout>.tr-card{min-width:0;width:100%;margin:0;overflow:hidden}
  #securityView .sec-layout>.sec-events-card{grid-column:1/-1}
  #securityView .sec-config-row{flex-wrap:wrap;min-width:0}
  #securityView .sec-config-row label{min-width:0;max-width:100%;flex-wrap:wrap}
  #securityView .sec-config-box{min-width:0;max-width:100%;overflow:hidden}
  #securityView #secBanForm{display:grid;grid-template-columns:minmax(150px,1fr) 100px minmax(180px,1.5fr) auto;gap:8px;align-items:center;width:100%;margin-bottom:12px}
  #securityView #secBanForm input{width:100%!important;max-width:none!important;min-width:0}
  #securityView #secBanBtn{white-space:nowrap}
  #securityView #secBansList,#securityView #secEventsList,#securityView #secEnvChecks,#securityView #secLastTest{min-width:0;max-width:100%;overflow-wrap:anywhere}
  #securityView #secBansList{max-height:300px;overflow-y:auto}
  #securityView #secEventsList{max-height:440px;overflow-y:auto;padding-right:4px}
  #securityView .sec-evt{grid-template-columns:minmax(105px,145px) minmax(0,1fr);gap:5px 12px;padding:9px 4px}
  #securityView .sec-evt>span:last-child{min-width:0;overflow-wrap:anywhere;word-break:break-word}
  #securityView .sec-ban{align-items:flex-start;min-width:0;gap:10px}
  #securityView .sec-ban>span{min-width:0;overflow-wrap:anywhere;word-break:break-word}
  #securityView .sec-ban .tool-btn{flex:0 0 auto}
  @media(max-width:760px){
    #securityView .sec-layout{grid-template-columns:minmax(0,1fr)}
    #securityView .sec-layout>.sec-events-card{grid-column:auto}
    #securityView #secBanForm{grid-template-columns:minmax(0,1fr) 100px}
    #securityView #secBanReason{grid-column:1/-1}
    #securityView #secBanBtn{grid-column:1/-1;width:100%}
    #securityView .sec-evt{grid-template-columns:minmax(0,1fr);gap:3px}
  }
  .sec-status-grid{display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:14px}
  .sec-module{background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 12px}
  .sec-mod-head{display:flex; align-items:center; gap:8px; font-size:12.5px}
  .sec-mod-head b{font-weight:600}
  .sec-mod-status{display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:6px; background:var(--line); color:var(--ink-dim); font-family:'JetBrains Mono',monospace; font-size:12px; flex:0 0 auto}
  .sec-module.ok .sec-mod-status{background:rgba(92,174,125,.16); color:#5cae7d}
  .sec-module.warn .sec-mod-status{background:rgba(214,159,63,.16); color:#d69f3f}
  .sec-module.bad .sec-mod-status{background:rgba(208,87,63,.16); color:#d0573f}
  .sec-module.off .sec-mod-status{background:var(--line); color:var(--ink-dim)}
  .sec-mod-detail{color:var(--ink-dim); font-size:11.5px; margin:6px 0 0 0; line-height:1.45}
  .sec-config-row{margin-bottom:9px; display:flex; align-items:center; gap:8px; font-size:12.5px}
  .sec-config-row input[type=number]{padding:4px 6px; border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--ink); font-family:'JetBrains Mono',monospace}
  .sec-config-box{background:rgba(0,0,0,.03); border:1px dashed var(--line); border-radius:8px; padding:8px 10px; margin-bottom:9px}
  .sec-toggle{display:flex; align-items:center; gap:7px; font-size:12.5px; cursor:pointer}
  .sec-toggle input{accent-color:var(--teal, #5a8a86)}
  .attr-field{display:block; margin-bottom:9px; font-size:12.5px}
  .attr-field > span{display:block; margin-bottom:4px; color:var(--ink-dim)}
  .attr-field input[type=text], .attr-field input[type=number], .attr-field textarea, .attr-field select{width:100%; padding:6px 8px; border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--ink)}
  .attr-field textarea{resize:vertical; font-family:inherit}
  .attr-field.mini input[type=number]{width:90px}
  .attr-row{display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:7px 8px; border:1px solid var(--line); border-radius:8px; margin-bottom:6px; background:var(--bg)}
  .attr-row .attr-name{flex:1 1 160px; min-width:140px; padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:var(--card); color:var(--ink)}
  .attr-row .attr-key{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim)}
  .attr-row .attr-key code{color:var(--ink)}
  .attr-empty{color:var(--ink-dim); font-size:12px; padding:6px 2px}
  .sec-check{display:flex; align-items:flex-start; gap:8px; padding:5px 6px; border-radius:6px; font-size:12.5px; margin-bottom:4px; background:var(--bg)}
  .sec-check .dot{width:8px; height:8px; border-radius:50%; background:var(--ink-dim); margin-top:5px; flex:0 0 auto}
  .sec-check.ok .dot{background:#5cae7d}
  .sec-check.warn .dot{background:#d69f3f}
  .sec-check.bad .dot{background:#d0573f}
  .sec-check .t{flex:1}
  .sec-check .t b{display:block; font-weight:600}
  .sec-check .t span{color:var(--ink-dim); font-size:11.5px}
  .sec-evt{display:grid; grid-template-columns:70px 1fr; gap:4px 10px; padding:6px 4px; border-bottom:1px dashed var(--line); font-size:12px}
  .sec-evt:last-child{border-bottom:none}
  .sec-evt .when{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim)}
  .sec-evt .who{color:var(--ink-dim)}
  .sec-evt .sev{font-family:'JetBrains Mono',monospace; font-size:9.5px; text-transform:uppercase; letter-spacing:.05em; padding:1px 5px; border-radius:4px; margin-left:6px}
  .sec-evt .sev.info{background:rgba(90,138,134,.15); color:#5a8a86}
  .sec-evt .sev.warn{background:rgba(214,159,63,.15); color:#d69f3f}
  .sec-evt .sev.danger{background:rgba(208,87,63,.15); color:#d0573f}
  .sec-ban{display:flex; justify-content:space-between; gap:8px; align-items:center; padding:6px 4px; border-bottom:1px dashed var(--line); font-size:12px}
  .sec-ban:last-child{border-bottom:none}
  .sec-ban .ip{font-family:'JetBrains Mono',monospace; font-weight:600}
  .sec-ban .why{color:var(--ink-dim); font-size:11.5px}
  .mini{font-size:11px; padding:3px 9px; margin-left:6px; vertical-align:middle}
  .links-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin:4px 0 16px}
  .links-head h2{font-family:'Fraunces',serif;font-size:23px;font-weight:500;margin:0 0 5px}
  .links-stats{display:grid;grid-template-columns:repeat(3,minmax(100px,130px)) minmax(220px,1fr);gap:9px;margin:0 0 15px}
  .links-stat,.links-tags-stat{background:var(--card);border:1px solid var(--line);border-radius:9px;padding:10px 12px;min-width:0}
  .links-stat span,.links-tags-stat>span{display:block;color:var(--ink-dim);font:10px 'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:.04em}
  .links-stat strong{display:block;font:22px 'Fraunces',serif;font-weight:500;margin-top:3px;color:var(--ink)}
  .links-tag-filters{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}
  .links-tag-filter{border:1px solid var(--line-strong);border-radius:999px;padding:5px 9px;background:transparent;color:var(--ink-dim);font:10.5px 'JetBrains Mono',monospace;cursor:pointer}
  .links-tag-filter:hover,.links-tag-filter.active{border-color:var(--gold);color:var(--gold);background:rgba(201,162,39,.07)}
  .links-tag-filter small{opacity:.7;margin-left:4px}.links-muted{color:var(--ink-dim);font-size:11px}

  .links-head p{color:var(--ink-dim);font-size:12.5px;line-height:1.55;max-width:620px;margin:0}
  .links-editor{background:var(--card);border:1px solid var(--line-strong);border-radius:12px;padding:16px;margin:0 0 16px}
  .links-editor[hidden]{display:none}.links-editor h3{font-family:'Fraunces',serif;font-weight:500;margin:0 0 10px}
  .links-editor label{display:block;color:var(--ink-dim);font:10.5px 'JetBrains Mono',monospace;text-transform:uppercase;margin:11px 0 5px}
  .links-editor input[type=text],.links-editor input[type=url],.links-editor textarea{width:100%;background:var(--bg);border:1px solid var(--line-strong);border-radius:8px;padding:10px 12px;color:var(--ink);font:14px 'Inter',sans-serif}
  .links-editor textarea{resize:vertical}.links-editor .links-active{display:flex;align-items:center;gap:8px;text-transform:none;font:12px 'Inter',sans-serif}.links-editor .links-active input{accent-color:var(--gold)}
  .links-editor-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px;flex-wrap:wrap}.links-hint{font-size:11.5px;color:var(--ink-dim);line-height:1.5;margin:8px 0 14px}.links-feedback{font-size:12px;color:var(--teal);margin-top:8px;min-height:16px}
  .links-list{display:flex;flex-direction:column;gap:9px}.links-item{display:grid;grid-template-columns:28px minmax(0,1fr) auto;gap:10px;align-items:start;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:13px}
  .links-item.is-hidden{opacity:.62}.links-item.dragging{opacity:.35;border-style:dashed}.links-item.drag-over{border-color:var(--gold);background:rgba(201,162,39,.06)}
  .links-grip{border:0;background:none;color:var(--ink-dim);font-size:20px;line-height:1;padding:2px;cursor:grab;touch-action:none}
  .links-item-title{font-family:'Fraunces',serif;font-size:15px;font-weight:500;overflow-wrap:anywhere}.links-item-title a{text-decoration:none}.links-item-title a:hover{color:var(--gold)}
  .links-item-url{font:10.5px 'JetBrains Mono',monospace;color:var(--teal);overflow-wrap:anywhere;margin-top:3px}.links-item-desc{font-size:12.5px;line-height:1.5;color:var(--ink-dim);margin-top:7px;white-space:pre-wrap;overflow-wrap:anywhere}
  .links-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}.links-tag{border:1px solid var(--line-strong);border-radius:999px;padding:3px 8px;color:var(--ink-dim);font:10px 'JetBrains Mono',monospace}
  .links-item-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.links-item-actions button{background:none;border:1px solid var(--line-strong);color:var(--ink-dim);border-radius:16px;padding:6px 10px;font:10.5px 'JetBrains Mono',monospace;cursor:pointer}.links-item-actions button:hover{border-color:var(--gold);color:var(--gold)}
  @media(max-width:760px){.links-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.links-tags-stat{grid-column:1/-1}}@media(max-width:600px){.links-item{grid-template-columns:22px minmax(0,1fr)}.links-item-actions{grid-column:2;justify-content:flex-start}}
</style>
</head>
<body>

<div class="wrap">
  <header class="top">
    <div class="wordmark"><a href="index.php">zmogilna</a><span class="sub">admin</span></div>
    <div class="header-actions">
      <button type="button" class="tool-btn theme-btn" id="themeBtn" aria-label="Przełącz tryb jasny / ciemny" title="Tryb jasny / ciemny">
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
      </button>
      <a class="home-link" href="index.php">← Wróć do strony głównej</a>
      <button type="button" class="logout-btn" id="logoutBtn" style="display:none;">wyloguj</button>
    </div>
  </header>

  <div class="login-screen" id="loginScreen">
    <h1>Panel administracyjny</h1>
    <p>Zaloguj się, żeby przeglądać i usuwać komentarze dodane na stronie zmogilna.</p>
    <div class="login-error" id="loginError"></div>
    <form class="login-form" id="loginForm" autocomplete="on">
      <label for="loginUser">Login</label>
      <input type="text" id="loginUser" name="user" autocomplete="username" required>
      <label for="loginPass">Hasło</label>
      <input type="password" id="loginPass" name="pass" autocomplete="current-password" required>
      <button type="submit" class="login-submit" id="loginSubmit">Zaloguj</button>
    </form>
  </div>

  <div class="admin-panel" id="adminPanel">
    <div class="admin-toolbar">
      <div class="admin-who">
        zalogowano jako <b id="adminUser"></b>
        <span class="admin-session" id="adminSession"></span>
      </div>
      <div class="header-actions">
        <button type="button" class="tool-btn active" id="tabComments">Komentarze</button>
        <button type="button" class="tool-btn" id="tabPhotos" title="Zdjęcia — liczba zgłoszeń do zatwierdzenia">Zdjęcia <span class="badge" id="photosBadge">0</span></button>
        <button type="button" class="tool-btn" id="tabProjects" title="Projekty robocze — listy zadań i gotowe prompty">Projekty <span class="badge" id="projectsBadge">0</span></button>
        <button type="button" class="tool-btn" id="tabAttraction" title="Atrakcje — etykiety i sekcja na stronie głównej">Atrakcje</button>
        <button type="button" class="tool-btn notes-btn" id="notesBtn" title="Notatnik — lista zadań">Notatnik <span class="badge" id="notesBadge">0</span></button>
        <button type="button" class="tool-btn" id="blockedBtn" title="Zablokowane nazwy — loginy, których nie wolno użyć">Zablokowane nazwy <span class="badge" id="blockedBadge">0</span></button>
    <button type="button" class="tool-btn" id="wordsBtn" title="Wulgaryzmy — wyrazy blokowane w komentarzach">Wulgaryzmy <span class="badge" id="wordsBadge">0</span></button>
    <button type="button" class="tool-btn" id="reportsBtn" title="Zgłoszenia komentarzy i zdjęć od czytelników">Zgłoszenia <span class="badge" id="reportsBadge">0</span></button>
        <button type="button" class="tool-btn" id="notifyBtn" title="Powiadomienia — alerty i konfiguracja typów">Powiadomienia <span class="badge" id="notifyBadge">0</span></button>
        <button type="button" class="tool-btn" id="tabUsers" title="Użytkownicy — punkty, rangi, bany">Użytkownicy</button>
        <button type="button" class="tool-btn" id="tabTraffic" title="Statystyki ruchu — odsłony, unikalni, źródła, urządzenia">Statystyki</button>
        <button type="button" class="tool-btn" id="tabSecurity" title="Bezpieczeństwo — HTTPS, nagłówki, bany, dziennik zdarzeń">Bezpieczeństwo</button>
        <button type="button" class="tool-btn" id="tabMaintenance" title="Przerwa techniczna — blokada strony z oknem logowania dla administratora">Przerwa techniczna</button>
        <button type="button" class="tool-btn" id="tabSettings" title="Ustawienia — konfiguracja funkcji strony">Ustawienia</button>
        <button type="button" class="tool-btn" id="tabLinks" title="Przydatne linki — katalog odnośników, narzędzi i profili">Przydatne linki</button>

        <button type="button" class="tool-btn" id="refreshBtn" title="Odśwież">Odśwież</button>
      </div>
    </div>

    <div id="commentsView">
      <div class="stats-row" id="statsRow">
        <div class="stat-card"><div class="label">Łącznie</div><div class="value" id="statTotal">—</div></div>
        <div class="stat-card"><div class="label">Dziś</div><div class="value" id="statToday">—</div></div>
        <div class="stat-card"><div class="label">7 dni</div><div class="value" id="statWeek">—</div></div>
      </div>

      <div class="top-photos" id="topPhotos" style="display:none;">
        <h3>Najczęściej komentowane zdjęcia</h3>
        <div class="top-photos-list" id="topPhotosList"></div>
      </div>

      <div class="filters-row">
        <input type="text" class="admin-search" id="searchInput" placeholder="Szukaj komentarza, autora, zdjęcia…">
        <select class="filter-select" id="sortSelect" title="Sortowanie">
          <option value="newest">Najnowsze</option>
          <option value="oldest">Najstarsze</option>
        </select>
        <select class="filter-select" id="periodSelect" title="Okres">
          <option value="">Cały czas</option>
          <option value="24h">Ostatnie 24h</option>
          <option value="7d">Ostatnie 7 dni</option>
        </select>
        <div class="photo-filter-chip" id="photoFilterChip">
          <span id="photoFilterLabel"></span>
          <button type="button" id="photoFilterClear" aria-label="Wyczyść filtr">×</button>
        </div>
      </div>

      <div class="admin-count-line">
        <span>Komentarze: <b id="adminCount">0</b></span>
        <label style="cursor:pointer; user-select:none;">
          <input type="checkbox" id="selectAll" style="accent-color:var(--gold); vertical-align:middle; margin-right:4px;">
          zaznacz widoczne
        </label>
      </div>

      <div class="admin-list" id="commentsList"></div>
      <button type="button" class="load-more-btn" id="loadMoreBtn" style="display:none;">Wczytaj więcej</button>

      <div class="bulk-bar" id="bulkBar">
        <span>Zaznaczone: <b id="bulkCount">0</b></span>
        <div class="bulk-actions">
          <button type="button" class="bulk-clear-btn" id="bulkClear">Odznacz</button>
          <button type="button" class="bulk-del-btn" id="bulkDelete">Usuń zaznaczone</button>
        </div>
      </div>

      <div class="log-section">
        <h3>Ostatnie działania</h3>
        <div class="log-list" id="logList"><div class="admin-empty" style="padding:16px 0;">Brak wpisów.</div></div>
      </div>
    </div>

    <div id="photosView" style="display:none;">
      <div class="stats-row">
        <div class="stat-card clickable" data-stat="all" role="button" tabindex="0" title="Pokaż wszystkie zdjęcia"><div class="label">Zdjęcia</div><div class="value" id="phTotal">—</div></div>
        <div class="stat-card clickable" data-stat="pub" role="button" tabindex="0" title="Pokaż opublikowane"><div class="label">Opublikowane</div><div class="value" id="phPub">—</div></div>
        <div class="stat-card clickable" data-stat="hidden" role="button" tabindex="0" title="Pokaż ukryte i zaplanowane"><div class="label">Ukryte</div><div class="value" id="phHidden">—</div></div>
        <div class="stat-card clickable" data-stat="cats" role="button" tabindex="0" title="Zarządzaj kategoriami"><div class="label">Kategorie</div><div class="value" id="phCats">—</div></div>
        <div class="stat-card clickable" data-stat="pending" role="button" tabindex="0" title="Pokaż zgłoszenia użytkowników czekające na moderację"><div class="label">Zgłoszenia</div><div class="value" id="phPending">—</div></div>
      </div>

      <div class="filters-row">
        <input type="text" class="admin-search" id="phSearch" placeholder="Szukaj zdjęcia, opisu, miejsca…">
        <select class="filter-select" id="phCategory" title="Kategoria">
          <option value="">Wszystkie kategorie</option>
          <option value="none">Bez kategorii</option>
        </select>
        <select class="filter-select" id="phPublished" title="Status">
          <option value="">Wszystkie</option>
          <option value="1">Opublikowane</option>
          <option value="0">Ukryte</option>
          <option value="pending">Zgłoszenia (oczekujące)</option>
          <option value="attractions">Atrakcje</option>
        </select>
        <select class="filter-select" id="phUser" title="Zgłaszający — zdjęcia zgłoszone przez danego użytkownika">
          <option value="">Wszyscy zgłaszający</option>
        </select>
        <button type="button" class="tool-btn" id="phAddBtn">+ Dodaj zdjęcie</button>
        <button type="button" class="tool-btn" id="phCatBtn">Kategorie</button>
        <button type="button" class="tool-btn" id="phScanBtn" title="Zarejestruj pliki wrzucone ręcznie do uploads/photos/">Skanuj katalog</button>
      </div>

      <div class="admin-count-line">
        <span>Zdjęcia: <b id="phCount">0</b></span>
        <label style="cursor:pointer; user-select:none;">
          <input type="checkbox" id="phSelectVisible" style="accent-color:var(--gold); vertical-align:middle; margin-right:4px;">
          zaznacz widoczne
        </label>
        <button type="button" class="bulk-clear-btn" id="phSelectResults" title="Zaznacz wszystkie zdjęcia pasujące do aktualnych filtrów (także spoza widocznej strony)">zaznacz wyniki</button>
      </div>

      <div class="admin-list" id="photosList"></div>
      <button type="button" class="load-more-btn" id="phMoreBtn" style="display:none;">Wczytaj więcej</button>

      <div class="bulk-bar" id="phBulkBar">
        <span>Zaznaczone: <b id="phBulkCount">0</b></span>
        <div class="bulk-actions">
          <button type="button" class="bulk-clear-btn" id="phBulkClear">Odznacz</button>
          <button type="button" class="bulk-del-btn" id="phBulkApprove">Zatwierdź zaznaczone</button>
        </div>
      </div>
    </div>

    <div id="linksView" style="display:none;">
      <div class="links-head"><h2>Przydatne linki</h2><div class="header-actions"><button type="button" class="tool-btn" id="linksExportBtn" title="Eksport wszystkich linków do PDF lub drukowanie">PDF / Drukuj</button><button type="button" class="tool-btn" id="linksAddBtn">+ Dodaj link</button></div></div>
      <div class="links-stats">
        <div class="links-stat"><span>Wszystkie linki</span><strong id="linksTotal">0</strong></div>
        <div class="links-stat"><span>Aktywne</span><strong id="linksActiveTotal">0</strong></div>
        <div class="links-stat"><span>Hasztagi</span><strong id="linksTagTotal">0</strong></div>
        <div class="links-tags-stat"><span>Filtruj po hasztagu</span><div id="linksTagFilters" class="links-tag-filters"><span class="links-muted">Brak hasztagów</span></div></div>
      </div>
      <div class="filters-row"><input type="search" class="admin-search" id="linksSearch" placeholder="Szukaj nazwy, opisu lub adresu…"><select class="filter-select" id="linksStatus"><option value="">Wszystkie wpisy</option><option value="1">Aktywne</option><option value="0">Ukryte</option></select><span class="admin-count-line" id="linksCount" style="margin:0;"></span></div>
      <div class="links-editor" id="linksEditor" hidden><h3 id="linksEditorTitle">Nowy link</h3><input type="hidden" id="linksId">
        <label for="linksName">Nazwa</label><input type="text" id="linksName" maxlength="120" placeholder="np. Google Search Console">
        <label for="linksUrl">Adres URL</label><input type="url" id="linksUrl" maxlength="2048" placeholder="https://…"><div class="links-hint" id="linksPreviewStatus" role="status">Po wklejeniu adresu strona zostanie automatycznie sprawdzona. Nazwa i opis uzupełnią się, jeśli pola są puste.</div>
        <label for="linksDescription">Opis / notatka</label><textarea id="linksDescription" rows="3" maxlength="1000" placeholder="Do czego służy ten link?"></textarea>
        <label for="linksTags">Hasztagi</label><input type="text" id="linksTags" maxlength="500" placeholder="#google #seo #analityka">
        <p class="links-hint">Wpisz kilka hasztagów oddzielonych spacją lub przecinkiem. Hasztagi służą do wyszukiwania i filtrowania, nie tworzą kategorii.</p>
        <label class="links-active"><input type="checkbox" id="linksActive" checked> Link aktywny</label>
        <div class="links-editor-actions"><button type="button" class="tool-btn" id="linksCancelBtn">Anuluj</button><button type="button" class="tool-btn" id="linksSaveBtn">Zapisz link</button></div><div class="links-feedback" id="linksFeedback" role="status"></div>
      </div>
      <div class="links-list" id="linksList"><div class="admin-empty">Wczytywanie linków…</div></div>
      <p class="links-hint">Zmieniaj kolejność, przeciągając wpisy za uchwyt ⠿. Nowa kolejność zapisuje się automatycznie.</p>
    </div>

    <div id="usersView" style="display:none;">
      <div class="stats-row">
        <div class="stat-card clickable" data-uf="all" role="button" tabindex="0" title="Pokaż wszystkich użytkowników"><div class="label">Użytkownicy</div><div class="value" id="usrTotal">—</div></div>
        <div class="stat-card clickable" data-uf="active" role="button" tabindex="0" title="Pokaż aktywne konta"><div class="label">Aktywne</div><div class="value" id="usrActive">—</div></div>
        <div class="stat-card clickable" data-uf="banned" role="button" tabindex="0" title="Pokaż zbanowane konta"><div class="label">Zbanowane</div><div class="value" id="usrBanned">—</div></div>
        <div class="stat-card clickable" data-uf="manual" role="button" tabindex="0" title="Konta z ręcznie ustawioną rangą"><div class="label">Ręczne rangi</div><div class="value" id="usrManual">—</div></div>
      </div>

      <div class="filters-row">
        <input type="text" class="admin-search" id="usrSearch" placeholder="Szukaj loginu, wyświetlanej nazwy, e-maila…">
        <select class="filter-select" id="usrRank" title="Ranga">
          <option value="">Wszystkie rangi</option>
        </select>
        <select class="filter-select" id="usrStatus" title="Status">
          <option value="">Status: wszyscy</option>
          <option value="active">Aktywne</option>
          <option value="banned">Zbanowane</option>
        </select>
        <select class="filter-select" id="usrSort" title="Sortowanie">
          <option value="points">Punkty</option>
          <option value="photos">Zdjęcia</option>
          <option value="comments">Komentarze</option>
          <option value="likes">Polubienia</option>
          <option value="newest">Najnowsi</option>
          <option value="username">Login</option>
        </select>
        <input type="number" class="admin-search" id="usrMinPoints" min="0" step="1" style="max-width:120px;" placeholder="Min. punktów" title="Minimalna liczba punktów">
        <button type="button" class="tool-btn" id="usrResetBtn" title="Wyczyść filtry">Wyczyść</button>
        <button type="button" class="tool-btn" id="usrAddBtn" title="Utwórz konto ręcznie (ranga VIP)">+ Dodaj użytkownika</button>
        <button type="button" class="tool-btn" id="usrRanksBtn" title="Edycja rang i progów">Rangi</button>
      </div>

      <div class="admin-count-line">
        <span>Znaleziono: <b id="usrCount">0</b></span>
      </div>

      <div class="admin-list" id="usersList"></div>
      <button type="button" class="load-more-btn" id="usrMoreBtn" style="display:none;">Wczytaj więcej</button>
    </div>

    <style>
      /* Rozbudowany panel statystyk — pod-zakładki i drobne dodatki do układu.
         Kolory i fonty dziedziczymy z motywu panelu (--card, --ink-dim itd.). */
      .st-subtabs{display:flex; flex-wrap:wrap; gap:8px; margin:2px 0 14px}
      .st-subtabs .tool-btn.active{background:var(--gold); color:#101c16; border-color:var(--gold)}
      .st-grid2{display:grid; grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr)); gap:14px; align-items:start}
      .st-grid2 .tr-card{margin-bottom:0}
      #stPanel-gsc{width:100%; max-width:100%; min-width:0; overflow:hidden}
      #stPanel-gsc .st-grid2,#stPanel-gsc .tr-card{min-width:0; max-width:100%}
      #stPanel-gsc .tr-card{overflow:hidden}
      #stPanel-gsc h3,#stPanel-gsc p,#stPanel-gsc .tr-muted,#stPanel-gsc .stat-card .label,#stPanel-gsc .stat-card .value{overflow-wrap:anywhere; word-break:break-word}
      #stPanel-gsc .filters-row,#stPanel-gsc .filters-row>*{min-width:0; max-width:100%}
      #stPanel-gsc .tr-table-wrap{width:100%; min-width:0; max-width:100%; overflow-x:auto}
      @media (max-width:640px){
        #stPanel-gsc .st-grid2{grid-template-columns:minmax(0,1fr); gap:10px}
        #stPanel-gsc .stats-row{grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px}
        #stPanel-gsc .filters-row{align-items:stretch}
        #stPanel-gsc .filters-row>*{flex:1 1 100%}
        #stPanel-gsc .filters-row .tool-btn{width:100%; white-space:normal; overflow-wrap:anywhere; justify-content:center; text-align:center}
        #stPanel-gsc .filters-row .filter-select{width:100%}
      }
      .st-thumb{width:46px; height:34px; object-fit:cover; border-radius:5px; background:var(--bg-alt); vertical-align:middle; margin-right:8px}
      .st-kpi-sub{font-family:'JetBrains Mono',monospace; font-size:10.5px; color:var(--ink-dim); margin-top:3px}
    .st-kpi-sub .up{color:#5cae7d}
    .st-kpi-sub .down{color:#d0573f}
      .st-empty{padding:16px; text-align:center; color:var(--ink-dim); font-style:italic; border:1px dashed var(--line-strong); border-radius:10px}
      .st-pill{display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; border:1px solid var(--line-strong); color:var(--ink-dim); margin-left:6px}
      .st-bad{color:var(--clay)}
      .st-ok{color:#5cae7d}
      .st-tag{display:inline-block; padding:1px 7px; border-radius:6px; font-size:10.5px; font-family:'JetBrains Mono',monospace; background:var(--bg-alt); border:1px solid var(--line); color:var(--ink-dim)}
      .st-actions a{color:var(--gold); text-decoration:none}
      .st-actions a:hover{text-decoration:underline}
    </style>

    <div id="trafficView" style="display:none;">
      <div class="filters-row">
        <select class="filter-select" id="stRange" title="Okres">
          <option value="today">Dziś</option>
          <option value="yesterday">Wczoraj</option>
          <option value="7d">7 dni</option>
          <option value="30d" selected>30 dni</option>
          <option value="90d">90 dni</option>
          <option value="month">Ten miesiąc</option>
          <option value="year">Ten rok</option>
          <option value="all">Cały okres</option>
          <option value="custom">Zakres niestandardowy…</option>
        </select>
        <span id="stCustom" style="display:none; align-items:center; gap:6px;">
          <input type="date" class="filter-select" id="stFrom" title="Od">
          <span class="tr-muted">–</span>
          <input type="date" class="filter-select" id="stTo" title="Do">
          <button type="button" class="tool-btn" id="stApply">Zastosuj</button>
        </span>
        <button type="button" class="tool-btn" id="stRefresh">Odśwież</button>
        <button type="button" class="tool-btn" id="stCsvBtn" title="Pobierz dane ruchu jako CSV (Excel)">Eksport CSV</button>
        <button type="button" class="tool-btn" id="stPdfBtn" title="Raport do wydruku — zapisz jako PDF dla reklamodawcy">PDF / Drukuj</button>
        <span class="admin-count-line" id="stMeta" style="margin:0; align-self:center;"></span>
      </div>

      <div class="st-subtabs" id="stTabs">
        <button type="button" class="tool-btn active" data-st="overview">Przegląd</button>
        <button type="button" class="tool-btn" data-st="traffic">Ruch i odwiedzający</button>
        <button type="button" class="tool-btn" data-st="photos">Zdjęcia i miejscowości</button>
        <button type="button" class="tool-btn" data-st="community">Rozwój społeczności</button>
        <button type="button" class="tool-btn" data-st="seo">SEO i źródła</button>
        <button type="button" class="tool-btn" data-st="gsc">Search Console</button>
        <button type="button" class="tool-btn" data-st="tech">Stan techniczny</button>
      </div>

      <div class="st-panel" id="stPanel-overview"><div class="tr-muted">Wczytywanie…</div></div>
      <div class="st-panel" id="stPanel-traffic" style="display:none;"></div>
      <div class="st-panel" id="stPanel-photos" style="display:none;"></div>
      <div class="st-panel" id="stPanel-community" style="display:none;"></div>
      <div class="st-panel" id="stPanel-seo" style="display:none;"></div>
      <div class="st-panel" id="stPanel-gsc" style="display:none;"><div class="tr-muted">Otwórz tę zakładkę, aby wyświetlić zapisane dane Search Console.</div></div>
      <div class="st-panel" id="stPanel-tech" style="display:none;"></div>

      <div class="admin-count-line"><span id="stNote" class="tr-muted"></span></div>
    </div>

    <div id="securityView" style="display:none;">
      <div class="filters-row">
        <button type="button" class="tool-btn" id="secRefreshBtn" title="Odśwież dane bezpieczeństwa">Odśwież</button>
        <button type="button" class="tool-btn" id="secTestBtn" title="Test na żywo: certyfikat TLS i nagłówki (loopback do serwera)">Test certyfikatu i nagłówków</button>
        <button type="button" class="tool-btn" id="secSaveBtn">Zapisz konfigurację</button>
        <span class="admin-count-line" id="secMeta" style="margin:0;align-self:center;"></span>
      </div>

      <!-- Jakie systemy działają i co mówią o sobie -->
      <div class="sec-status-grid" id="secModules">
        <div class="sec-module" data-mod="https"><div class="sec-mod-head"><span class="sec-mod-status">?</span><b>HTTPS / Let's Encrypt</b></div><div class="sec-mod-detail">—</div></div>
        <div class="sec-module" data-mod="hsts"><div class="sec-mod-head"><span class="sec-mod-status">?</span><b>HSTS</b></div><div class="sec-mod-detail">—</div></div>
        <div class="sec-module" data-mod="headers"><div class="sec-mod-head"><span class="sec-mod-status">?</span><b>Nagłówki bezpieczeństwa</b></div><div class="sec-mod-detail">—</div></div>
        <div class="sec-module" data-mod="login"><div class="sec-mod-head"><span class="sec-mod-status">?</span><b>Ochrona logowania</b></div><div class="sec-mod-detail">—</div></div>
        <div class="sec-module" data-mod="log"><div class="sec-mod-head"><span class="sec-mod-status">?</span><b>Dziennik zdarzeń</b></div><div class="sec-mod-detail">—</div></div>
      </div>

      <div class="stats-row" id="secKpis">
        <div class="stat-card static"><div class="label">Zdarzenia (24h)</div><div class="value" id="secKpiEvents">—</div></div>
        <div class="stat-card static"><div class="label">Nieudane logowania (24h)</div><div class="value" id="secKpiFails">—</div></div>
        <div class="stat-card static"><div class="label">Blokady (24h)</div><div class="value" id="secKpiLocked">—</div></div>
        <div class="stat-card static"><div class="label">Aktywne bany IP</div><div class="value" id="secKpiBans">—</div></div>
      </div>

      <div class="tr-grid sec-layout">
        <div class="tr-card">
          <h3>Konfiguracja</h3>
          <div class="sec-config-row">
            <label class="sec-toggle"><input type="checkbox" id="secCfgForceHttps"> Wymuszaj HTTPS <span class="muted">(przekierowanie na https://)</span></label>
          </div>
          <div class="sec-config-box" id="secCfgHstsBox" style="display:none;">
            <label class="sec-toggle"><input type="checkbox" id="secCfgHsts"> HSTS — nakazuj przeglądarce szyfrowanie <span class="muted">(tylko gdy HTTPS działa)</span></label>
            <div class="row-inline">
              <input type="number" id="secCfgHstsMaxAge" min="60" max="31536000" step="60" placeholder="max-age (s)" style="max-width:150px;">
              <label class="sec-toggle"><input type="checkbox" id="secCfgHstsPreload"> includeSubDomains + preload</label>
            </div>
          </div>
          <div class="sec-config-row"><label>Min. długość hasła: <input type="number" id="secCfgMinPasswordLen" min="8" max="128" style="width:70px;"></label></div>
          <div class="sec-config-row"><label>Próby logowania przed blokadą: <input type="number" id="secCfgLoginMaxAttempts" min="2" max="50" style="width:70px;"></label></div>
          <div class="sec-config-row"><label>Blokada konta (s): <input type="number" id="secCfgLoginLockSeconds" min="10" max="86400" style="width:90px;"></label></div>
          <div class="sec-config-row"><label>Sesja ważna (s): <input type="number" id="secCfgSessionTtl" min="900" max="2592000" style="width:90px;"></label></div>
          <div class="sec-config-row"><label>Trzymaj dziennik (dni): <input type="number" id="secCfgLogRetentionDays" min="1" max="3650" style="width:70px;"></label></div>
          <div class="sec-config-row"><label class="sec-toggle"><input type="checkbox" id="secCfgEventsEnabled"> Zapisuj zdarzenia i blokady IP</label></div>
        </div>

        <div class="tr-card">
          <h3>Ostatni test na żywo</h3>
          <div id="secLastTest"><p class="muted">Jeszcze nie uruchomiono. Kliknij „Test certyfikatu i nagłówków”.</p></div>
          <h3 style="margin-top:14px;">Audyt środowiska <button type="button" class="tool-btn mini" id="secAuditRefresh" title="Odśwież audyt">Odśwież</button></h3>
          <div id="secEnvChecks"><p class="muted">Ładowanie…</p></div>
        </div>

        <div class="tr-card">
          <h3>Bany IP <span class="muted">(fail2ban-lite)</span></h3>
          <div class="row-inline" id="secBanForm">
            <input type="text" id="secBanIpInput" placeholder="Adres IP" style="flex:1;max-width:160px;">
            <input type="number" id="secBanSeconds" value="3600" min="60" max="31536000" title="Na ile sekund" style="width:90px;">
            <input type="text" id="secBanReason" placeholder="Powód (opcjonalnie)" style="flex:1;">
            <button type="button" class="tool-btn" id="secBanBtn">Zbanuj</button>
          </div>
          <div id="secBansList"><p class="muted">Ładowanie…</p></div>
        </div>

        <div class="tr-card sec-events-card">
          <h3>Dziennik zdarzeń <button type="button" class="tool-btn mini danger" id="secClearEventsBtn">Wyczyść</button> <button type="button" class="tool-btn mini" id="secClearOldBtn" title="Usuń wpisy starsze niż okres retencji">Przytnij</button></h3>
          <div id="secEventsList"><p class="muted">Ładowanie…</p></div>
        </div>
      </div>

      <div class="admin-count-line" id="secNote"></div>
    </div>

    <div id="maintenanceView" style="display:none;">
      <div class="filters-row">
        <button type="button" class="tool-btn" id="maintRefreshBtn" title="Odśwież stan przerwy">Odśwież</button>
        <button type="button" class="tool-btn" id="maintSaveBtn">Zapisz</button>
        <span class="admin-count-line" id="maintMeta" style="margin:0;align-self:center;"></span>
      </div>

      <div class="tr-grid">
        <div class="tr-card">
          <h3>Przerwa techniczna</h3>
          <p class="muted" style="line-height:1.6;">Gdy przerwa jest włączona, odwiedzający zamiast strony widzą krótki komunikat i okno logowania (HTTP 503). Zalogować się w tym oknie może tylko konto z rangą administratora. Ty — mając aktywną sesję — korzystasz ze strony i panelu bez zmian.</p>
          <div class="sec-config-row">
            <label class="sec-toggle"><input type="checkbox" id="maintEnabled"> Włącz przerwę techniczną <span class="muted">(blokada strony dla odwiedzających)</span></label>
          </div>
          <div class="sec-config-row" style="display:block;">
            <label for="maintMessage" style="display:block;margin-bottom:4px;">Komunikat na stronie</label>
            <textarea id="maintMessage" rows="3" maxlength="500" style="width:100%;max-width:640px;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:10px 12px;color:var(--ink);font-family:inherit;font-size:14px;resize:vertical;"></textarea>
          </div>
          <div class="admin-count-line" id="maintStatus" style="margin-top:10px;"></div>
        </div>

        <div class="tr-card">
          <h3>Jak to działa</h3>
          <ul style="margin:0;padding-left:18px;line-height:1.7;color:var(--ink-dim);font-size:13px;">
            <li>Gość albo zwykły użytkownik widzi tylko komunikat i okno logowania.</li>
            <li>W oknie zaloguje się <b>wyłącznie</b> konto z rangą administratora.</li>
            <li>Administrator z aktywną sesją ma pełny dostęp do strony i panelu.</li>
            <li>Odpowiedź to <b>HTTP 503</b> z nagłówkiem <code>Retry-After</code> — komunikatu nie zaindeksują wyszukiwarki.</li>
            <li>Wyłączenie przerwy natychmiast przywraca normalny dostęp.</li>
          </ul>
        </div>
      </div>
    </div>

    <div id="settingsView" style="display:none;">
      <div class="set-banner"><b>Schemat ustawień.</b> Ta zakładka na razie tylko opisuje, jakie ustawienia mają powstać — nic tu nie jest zapisywane i żaden przełącznik nie zmienia strony. Pozycje oznaczone „już działa” prowadzą do istniejących zakładek.</div>
      <div class="set-subtabs" id="setSubtabs"></div>
      <div id="setBody"></div>
    </div>

    <div id="attractionView" style="display:none;">
      <div class="filters-row">
        <button type="button" class="tool-btn" id="attrRefreshBtn" title="Odśwież konfigurację">Odśwież</button>
        <button type="button" class="tool-btn" id="attrSaveBtn">Zapisz konfigurację</button>
        <span class="admin-count-line" id="attrMeta" style="margin:0;align-self:center;"></span>
      </div>

      <div class="tr-grid">
        <div class="tr-card">
          <h3>Etykiety atrakcji</h3>
          <p class="muted" style="font-size:11.5px;margin:0 0 10px 0;">Klucz tworzy się z nazwy (małe litery, myślniki). „Domyślne” = zaznaczone w formularzu zgłoszenia. Zmiana nazwy nie zmienia klucza — istniejące oznaczenia zostają.</p>
          <div id="attrLabels"></div>
          <div class="row-inline" style="margin-top:8px;">
            <input type="text" id="attrNewLabel" placeholder="Nazwa nowej etykiety" maxlength="60" style="flex:1;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--ink);">
            <button type="button" class="tool-btn" id="attrAddLabelBtn">Dodaj</button>
          </div>
        </div>

        <div class="tr-card">
          <h3>Sekcja na stronie głównej</h3>
          <label class="attr-field"><span>Tytuł sekcji</span><input type="text" id="attrTitle" maxlength="120"></label>
          <label class="attr-field"><span>Podtytuł</span><textarea id="attrSubtitle" rows="2" maxlength="240"></textarea></label>
          <div class="sec-config-row">
            <label>Liczba zdjęć: <input type="number" id="attrCount" min="1" max="24" style="width:80px;"></label>
            <label>Tryb: <select id="attrMode" style="padding:4px 6px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--ink);">
              <option value="latest">Najnowsze</option>
              <option value="random">Losowe</option>
              <option value="top">Najpopularniejsze</option>
            </select></label>
          </div>
          <div class="sec-config-row"><label>Karuzela przewija co (s): <input type="number" id="attrAutoplay" min="0" max="60" style="width:80px;"> <span class="muted">(0 = bez autoodtwarzania)</span></label></div>
          <div class="sec-config-row"><label>Maks. kategorii na zdjęcie: <input type="number" id="attrMaxLabels" min="1" max="5" style="width:80px;"> <span class="muted">(1–5, polecane 2 — chroni przed „choinką”)</span></label></div>
          <div class="sec-config-row"><label class="sec-toggle"><input type="checkbox" id="attrShowHome"> Pokazuj sekcję na stronie głównej</label></div>
          <div class="sec-config-row"><label class="sec-toggle"><input type="checkbox" id="attrEnableSubmission"> Pozwalaj oznaczać zdjęcia przy zgłoszeniu</label></div>
        </div>

        <div class="tr-card">
          <h3>Zachęcanie do powrotu</h3>
          <p class="muted" style="font-size:11.5px;margin:0 0 10px 0;">Bonus nalicza się jednorazowo, gdy zatwierdzone zdjęcie jest oznaczone jako atrakcja. Powiadomienie o typie „Atrakcja turystyczna” sterujesz dodatkowo w zakładce Powiadomienia.</p>
          <div class="sec-config-row"><label>Bonus punktów za atrakcję: <input type="number" id="attrBonusPoints" min="0" max="500" style="width:80px;"> <span class="muted">(0 = bez bonusu)</span></label></div>
          <div class="sec-config-row"><label class="sec-toggle"><input type="checkbox" id="attrNotifyAttraction"> Powiadamiaj autora, gdy zdjęcie zostanie atrakcją</label></div>
        </div>
      </div>

      <div class="admin-count-line" id="attrNote"></div>
    </div>

    <div id="projectsView" style="display:none;">
      <div class="proj-layout">
        <aside class="proj-side">
          <div class="proj-side-head">
            <h3>Projekty robocze</h3>
            <button type="button" class="tool-btn mini" id="projAddBtn" title="Nowy projekt">+ Nowy</button>
          </div>
          <div class="proj-list" id="projList"></div>
          <div class="proj-side-form" id="projForm" style="display:none;">
            <input type="text" id="projName" placeholder="Nazwa projektu" maxlength="160">
            <textarea id="projDesc" placeholder="Opis projektu (opcjonalnie)" rows="3"></textarea>
            <div class="proj-form-actions">
              <button type="button" class="tool-btn mini" id="projFormCancel">Anuluj</button>
              <button type="button" class="tool-btn mini" id="projFormSave">Zapisz</button>
            </div>
          </div>
        </aside>

        <section class="proj-main">
          <div class="admin-empty" id="projEmpty" style="padding:32px 0;text-align:center;">Wybierz projekt z listy albo utwórz nowy przyciskiem „+ Nowy”.</div>

          <div id="projDetail" style="display:none;">
            <div class="proj-detail-head">
              <div>
                <h2 id="projTitle">—</h2>
                <p class="proj-desc" id="projDetailDesc"></p>
                <span class="proj-status" id="projStatus"></span>
              </div>
              <div class="proj-detail-actions">
                <button type="button" class="tool-btn" id="projEditBtn">Edytuj projekt</button>
                <button type="button" class="tool-btn danger" id="projDeleteBtn">Usuń projekt</button>
              </div>
            </div>

            <div class="notes-add">
              <input type="text" id="projTaskInput" placeholder="Dodaj zadanie do projektu i naciśnij Enter…" maxlength="200" autocomplete="off">
              <button type="button" class="modal-confirm save" id="projTaskAddBtn">Dodaj</button>
            </div>

            <div class="proj-tasks" id="projTasks"></div>
          </div>
        </section>
      </div>
    </div>

  </div>
</div>

<div class="modal-backdrop" id="modalBackdrop" role="dialog" aria-modal="true">
  <div class="modal" id="modalBox">
    <h2 id="modalTitle">Potwierdzenie</h2>
    <div class="modal-preview" id="modalPreview" style="display:none;"></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="modalCancel">Anuluj</button>
      <button type="button" class="modal-confirm" id="modalConfirm">Potwierdź</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="usrModalBackdrop" role="dialog" aria-modal="true" style="display:none;">
  <div class="modal modal-wide">
    <div class="admin-user-head">
      <div>
        <h2 id="usrModalName">—</h2>
        <span class="muted" id="usrModalLogin">—</span>
        <span class="rank-chip" id="usrModalRank" style="display:none;"></span>
        <span class="rank-chip" id="usrModalSpRank" style="display:none;"></span>
        <span class="banned-chip" id="usrModalBanned" style="display:none;">Zbanowany</span>
      </div>
      <button type="button" class="modal-cancel modal-close-x" id="usrModalClose" aria-label="Zamknij">×</button>
    </div>

    <div class="stats-row" id="usrModalCounters" style="grid-template-columns:repeat(5,1fr);">
      <div class="stat-card static"><div class="label">Zdjęcia</div><div class="value" id="umPhotos">—</div></div>
      <div class="stat-card static"><div class="label">Komentarze</div><div class="value" id="umComments">—</div></div>
      <div class="stat-card static"><div class="label">Polubienia</div><div class="value" id="umLikes">—</div></div>
      <div class="stat-card static"><div class="label">Punkty</div><div class="value" id="umPoints">—</div></div>
      <div class="stat-card static"><div class="label">Dołączył</div><div class="value" id="umJoined" style="font-size:14px;">—</div></div>
    </div>

    <div class="admin-user-panels">
      <div class="admin-user-panel">
        <h3>Dane konta</h3>
        <form id="usrEditForm" autocomplete="off">
          <label>Login</label>
          <input type="text" id="umUsername" required autocomplete="username">
          <label>E-mail (puste = brak)</label>
          <input type="email" id="umEmail" autocomplete="email">
          <label>Hasło (puste = bez zmian)</label>
          <input type="password" id="umPassword" autocomplete="new-password" placeholder="min. 8 znaków">
          <button type="submit" class="tool-btn" id="umSaveBtn">Zapisz dane</button>
        </form>

        <h3>Punkty</h3>
        <div class="row-inline">
          <input type="number" id="umDelta" min="-10000" max="10000" step="1" placeholder="+/- punkty" style="max-width:110px;">
          <input type="text" id="umNote" placeholder="Notatka (opcjonalnie)" style="flex:1;">
          <button type="button" class="tool-btn" id="umPointsBtn">Zastosuj</button>
        </div>

        <h3>Ranga (z punktów)</h3>
        <div class="row-inline">
          <select id="umRankSel" style="flex:1;"><option value="0">Automatyczna (wróć do punktów)</option></select>
          <button type="button" class="tool-btn" id="umRankBtn">Ustaw</button>
        </div>

        <h3>Ranga specjalna</h3>
        <div class="row-inline">
          <select id="umSpSel" style="flex:1;"><option value="0">Brak rangi specjalnej</option></select>
          <button type="button" class="tool-btn" id="umSpBtn">Ustaw</button>
        </div>
      </div>

      <div class="admin-user-panel">
        <h3>Akcje</h3>
        <button type="button" class="tool-btn" id="umRoleBtn" style="display:none; margin-bottom:8px;">Nadaj prawa administratora</button>
        <button type="button" class="tool-btn danger" id="umBanBtn">Zbanuj konto</button>
        <button type="button" class="tool-btn danger" id="umDeleteBtn" style="margin-top:8px;">Usuń konto</button>
        <p class="muted" id="umBannedInfo" style="margin-top:8px;"></p>

        <h3>Historia punktów</h3>
        <div class="log-list admin-points-history" id="umHistory"><p class="muted">Ładowanie…</p></div>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="usrAddBackdrop" role="dialog" aria-modal="true" style="display:none;">
  <div class="modal">
    <div class="admin-user-head">
      <div>
        <h2>Dodaj użytkownika</h2>
        <span class="muted">Konto dostaje rangę VIP, prywatny profil i nie ma uprawnień administratora.</span>
      </div>
      <button type="button" class="modal-cancel modal-close-x" id="usrAddClose" aria-label="Zamknij">×</button>
    </div>

    <div class="admin-user-panel">
      <form id="usrAddForm" autocomplete="off">
        <label for="uaUsername">Login</label>
        <input type="text" id="uaUsername" required minlength="3" maxlength="40" pattern="[A-Za-z0-9_.\-]+" title="3–40 znaków: litery, cyfry, _ . -" autocomplete="username">

        <label for="uaDisplay">Nazwa wyświetlana (puste = login)</label>
        <input type="text" id="uaDisplay" maxlength="120">

        <label for="uaEmail">E-mail (puste = brak)</label>
        <input type="email" id="uaEmail" autocomplete="off">

        <label for="uaPassword">Hasło</label>
        <input type="password" id="uaPassword" required minlength="8" autocomplete="new-password" placeholder="min. 8 znaków">

        <p class="muted" style="font-size:12px; line-height:1.55; margin:12px 0 0;">
          VIP jest widoczny tylko w panelu administracyjnym. Konto nie liczy się w statystykach,
          dopóki użytkownik sam nie włączy „Profilu publicznego”. W trakcie przerwy technicznej
          może się zalogować, ale pozostaje zwykłym użytkownikiem.
        </p>

        <div class="modal-actions">
          <button type="button" class="modal-cancel" id="usrAddCancel">Anuluj</button>
          <button type="submit" class="modal-confirm" id="usrAddSave">Utwórz konto</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="usrRanksBackdrop" role="dialog" aria-modal="true" style="display:none;">
  <div class="modal modal-wide">
    <div class="admin-user-head">
      <div>
        <h2>Rangi i progi punktów</h2>
        <span class="muted">Ranga użytkownika liczona z punktów; możesz też ustawić ją ręcznie. „★ spec.” = ranga specjalna (np. dla drużyny) — dodatkowa, nadawana wyłącznie przez admina, trzymana obok zwykłej rangi.</span>
      </div>
      <button type="button" class="modal-cancel modal-close-x" id="usrRanksClose" aria-label="Zamknij">×</button>
    </div>
    <div id="ranksEditor"><p class="muted">Ładowanie…</p></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="usrRanksCancel">Anuluj</button>
      <button type="button" class="modal-confirm" id="usrRanksSave">Zapisz rangi</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="phUploadBackdrop" role="dialog" aria-modal="true">
  <div class="modal">
    <h2>Dodaj zdjęcie</h2>
    <p class="modal-upload-sub" id="phUpSub">Trzy kroki: wybierz plik, poczekaj na dane, uzupełnij szczegóły.</p>
    <div class="admin-upload-step" id="phStepPick">
      <label for="phUpFiles">Zdjęcie</label>
      <div class="modal-preview" id="phEnvNote" style="display:none;"></div>
      <div class="admin-upload-drop" id="phUpDrop" tabindex="0" role="button" aria-label="Wybierz albo upuść zdjęcie">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div class="drop-title">Kliknij albo upuść zdjęcie lub film tutaj</div>
        <div class="picked" id="phUpPicked"></div>
      </div>
      <input type="file" id="phUpFiles" accept="image/*,video/*,.heic,.heif,.avif,.tif,.tiff,.bmp,.mov,.mp4,.m4v,.webm" hidden>
    </div>
    <div class="admin-upload-step" id="phStepScan">
      <div class="admin-upload-scan">
        <div class="admin-scan-ring"></div>
        <div class="scan-title">Czytamy dane ze zdjęcia</div>
        <div class="scan-file" id="phUpScanFile">—</div>
        <ul class="admin-scan-steps" id="phUpScanSteps">
          <li data-step="exif">odczyt metadanych (EXIF)</li>
          <li data-step="gps">sprawdzanie współrzędnych GPS</li>
          <li data-step="geo">ustalanie lokalizacji i gminy</li>
          <li data-step="cat">dobieranie kategorii</li>
        </ul>
      </div>
    </div>
    <div class="admin-upload-step" id="phStepForm">
      <div class="admin-upload-preview" id="phUpPreview">
        <img id="phUpThumb" alt="Podgląd wybranego zdjęcia">
        <div><div class="name" id="phUpName">—</div><div class="meta" id="phUpMeta">—</div></div>
      </div>
      <label for="phUpCategory">Kategoria</label>
      <select id="phUpCategory"><option value="">— bez kategorii —</option></select>
      <label for="phUpPlace">Miejsce (opcjonalnie)</label>
      <input type="text" id="phUpPlace" placeholder="np. Jezioro Mogileńskie">
      <label for="phUpCoordinates">Link do miejsca z Map Google (opcjonalnie)</label>
      <input type="url" id="phUpCoordinates" autocomplete="off" spellcheck="false" placeholder="https://www.google.com/maps/@52.6337,17.9810,15z">
      <div class="hint">Otwórz miejsce w Mapach Google, wybierz „Udostępnij” i wklej skopiowany link. Współrzędne odczytamy z linku same — nie musisz ich wpisywać.</div>
      <div class="hint" id="phUpGeoNote" style="display:none;"></div>
      <label for="phUpDate">Data zdjęcia (opcjonalnie)</label>
      <input type="date" id="phUpDate">
      <div class="hint">Puste = data z pliku (EXIF), a gdy jej brak — dzień wgrania.</div>
    </div>

    <div id="phAdminOptions" class="admin-upload-step">
    <label style="display:flex; align-items:center; gap:8px; text-transform:none; font-size:12px; margin-top:12px;">
      <input type="checkbox" id="phUpPublished" checked style="width:auto; accent-color:var(--gold);">
      opublikuj od razu
    </label>
    <div id="phUpScheduleBox" style="display:none;">
      <label for="phUpPublishAt">Zaplanuj publikację</label>
      <input type="datetime-local" id="phUpPublishAt">
      <div class="hint">Zdjęcie pojawi się w galerii samo o wybranej godzinie. Puste = zostanie ukryte, dopóki go nie opublikujesz ręcznie.</div>
    </div>
    </div>
    <div class="modal-preview" id="phUpResult" style="display:none;"></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="phUpCancel">Anuluj</button>
      <button type="button" class="modal-confirm save" id="phUpConfirm">Wyślij</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="phEditBackdrop" role="dialog" aria-modal="true">
  <div class="modal">
    <h2 id="phEditTitle">Edytuj zdjęcie</h2>
    <div class="modal-preview" id="phEditPreview" style="display:none;"></div>
    <label for="phEdTitle">Krótki opis zapasowy</label>
    <input type="text" id="phEdTitle" placeholder="używany, gdy pełny opis jest pusty">
    <label for="phEdDesc">Opis (plakietka na zdjęciu)</label>
    <textarea id="phEdDesc" placeholder="Pokażemy go na powiększonym zdjęciu"></textarea>
    <div class="hint">Nazwa zdjęcia w galerii zawsze pochodzi z miejscowości lub kategorii.</div>
    <label for="phEdCategory">Kategoria</label>
    <select id="phEdCategory"><option value="">— bez kategorii —</option></select>
    <label for="phEdPlace">Miejsce</label>
    <input type="text" id="phEdPlace">
    <label for="phEdDate">Data wykonania</label>
    <input type="date" id="phEdDate">
    <label for="phEdCoordinates">Link do miejsca z Map Google</label>
    <input type="url" id="phEdCoordinates" autocomplete="off" spellcheck="false" placeholder="https://www.google.com/maps/@52.6337,17.9810,15z">
    <div class="hint">Wklej link do miejsca z Map Google — współrzędne odczytamy z niego. Pole puste = usuń lokalizację zdjęcia.</div>

    <label for="phEdEditStatus">Czy zdjęcie było modyfikowane?</label>
    <select id="phEdEditStatus">
      <option value="">Automatycznie (ocena z danych pliku)</option>
      <option value="original">Oryginał — bez edycji</option>
      <option value="suspect">Możliwa edycja</option>
      <option value="edited">Edytowane</option>
      <option value="unknown">Nie da się ustalić</option>
    </select>
    <div id="phEdEditNoteBox" style="display:none;">
      <label for="phEdEditNote">Notatka do statusu (opcjonalnie)</label>
      <input type="text" id="phEdEditNote" maxlength="250" placeholder="np. zdjęcie od mieszkańca, zrobione telefonem">
    </div>
    <div class="edit-auto-note" id="phEdAutoNote"></div>

    <label style="margin-top:12px;">Atrakcje turystyczne</label>
    <div class="ed-attr-checks" id="phEdAttrList"></div>
    <div class="hint">Zaznacz etykiety, jeśli to miejsce to atrakcja turystyczna. Oznaczenie jest widoczne publicznie dopiero po opublikowaniu i zatwierdzeniu zdjęcia.</div>
    <label for="phEdAttrNote">Notatka do atrakcji (opcjonalnie)</label>
    <input type="text" id="phEdAttrNote" maxlength="255" placeholder="np. widok na jezioro o zachodzie słońca">

    <label style="display:flex; align-items:center; gap:8px; text-transform:none; font-size:12px; margin-top:12px;">
      <input type="checkbox" id="phEdPublished" style="width:auto; accent-color:var(--gold);">
      opublikowane
    </label>
    <div id="phEdScheduleBox" style="display:none;">
      <label for="phEdPublishAt">Zaplanuj publikację</label>
      <input type="datetime-local" id="phEdPublishAt">
      <div class="hint">Puste = zdjęcie pozostanie ukryte, dopóki go nie opublikujesz ręcznie.</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="phEdCancel">Anuluj</button>
      <button type="button" class="modal-confirm save" id="phEdSave">Zapisz</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="gminaBackdrop" role="dialog" aria-modal="true" aria-labelledby="gminaTitle">
  <div class="modal">
    <h2 id="gminaTitle">Sprawdź i zatwierdź zdjęcie</h2>
    <div class="modal-preview" id="revPreview" hidden><img id="revImg" alt="Zdjęcie do moderacji"></div>
    <p class="admin-geo-callout" id="revGeo" style="margin:10px 0;"></p>
    <div class="rev-grid" id="revExif"></div>
    <div class="rev-attr" id="revAttrWrap">
      <label>Etykiety atrakcji turystycznej</label>
      <div class="rev-attr-chips" id="revAttrChips"></div>
      <div class="rev-attr-add">
        <select id="revAttrSelect" aria-label="Wybierz etykietę do dodania"></select>
        <button type="button" class="modal-cancel" id="revAttrAdd">Dodaj etykietę</button>
      </div>
      <div class="hint">Etykiety zapiszą się razem z decyzją o zatwierdzeniu. Brak etykiet = zdjęcie nie jest oznaczone jako atrakcja.</div>
    </div>
    <label for="gminaCatSelect">Kategoria przy zatwierdzeniu</label>
    <select id="gminaCatSelect"><option value="">— zatwierdź bez kategorii —</option></select>
    <div class="hint" id="revCatHint">„Dodaj proponowaną kategorię” utworzy brakującą kategorię (np. podkategorię miejscowości pod gminą) i przypisze do niej to zdjęcie. Możesz też wskazać dowolną istniejącą kategorię albo zatwierdzić bez kategorii.</div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="gminaEdit">Edytuj zdjęcie</button>
      <button type="button" class="modal-cancel" id="gminaCancel">Anuluj</button>
      <button type="button" class="modal-cancel" id="gminaAssign">Zatwierdź z wybraną kategorią</button>
      <button type="button" class="modal-confirm save" id="gminaCreate">Dodaj proponowaną kategorię i zatwierdź</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="photoRejectBackdrop" role="dialog" aria-modal="true" aria-labelledby="photoRejectTitle">
  <div class="modal photo-reject-modal">
    <h2 id="photoRejectTitle">Powód odrzucenia zdjęcia</h2>
    <div class="modal-preview" id="photoRejectPhoto"></div>
    <label for="photoRejectReason">Wybierz powód</label>
    <select id="photoRejectReason" aria-describedby="photoRejectHelp">
      <option value="">— wybierz powód —</option>
      <option value="regulamin">Niezgodne z regulaminem</option>
      <option value="odleglosc">Za daleko od Mogilna i okolicy</option>
      <option value="obszar">Nie przedstawia Mogilna ani okolicy</option>
      <option value="jakosc">Niska jakość lub nieczytelne zdjęcie</option>
      <option value="inne">Wpiszę własny powód</option>
    </select>
    <div id="photoRejectCustom" hidden>
      <label for="photoRejectMessage">Własny powód</label>
      <textarea id="photoRejectMessage" maxlength="220" placeholder="Krótko wyjaśnij, dlaczego zdjęcie zostało odrzucone."></textarea>
    </div>
    <div class="hint" id="photoRejectHelp">Wybrany powód zostanie wysłany zgłaszającemu w powiadomieniu.</div>
    <div class="hint" id="photoRejectError" role="alert" style="display:none;color:var(--clay);"></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="photoRejectCancel">Anuluj</button>
      <button type="button" class="modal-confirm" id="photoRejectConfirm" disabled>Odrzuć zdjęcie</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="phCatBackdrop" role="dialog" aria-modal="true">
  <div class="modal">
    <h2>Kategorie</h2>
    <div id="phCatList"></div>
    <label for="phCatName" id="phCatFormLabel">Nowa kategoria</label>
    <input type="text" id="phCatName" placeholder="np. Strzelno">
    <label for="phCatParent">Leży w kategorii</label>
    <select id="phCatParent"><option value="">— kategoria główna —</option></select>
    <div class="hint" id="phCatParentHint"></div>
    <label for="phCatDesc">Krótki opis (widoczny w galerii)</label>
    <input type="text" id="phCatDesc" placeholder="np. Rotunda św. Prokopa i klasztor norbertanek.">
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="phCatClose">Zamknij</button>
      <button type="button" class="modal-cancel" id="phCatCancelEdit" style="display:none;">Anuluj edycję</button>
      <button type="button" class="modal-confirm save" id="phCatAdd">Dodaj</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="notesBackdrop" role="dialog" aria-modal="true" aria-labelledby="notesTitle">
  <div class="modal notes-modal">
    <div class="notes-head"><h2 id="notesTitle">Notatnik</h2><span class="notes-progress" id="notesProgress"></span></div>
    <div class="notes-add">
      <textarea id="notesInput" placeholder="Dodaj notatkę… (Ctrl + Enter, aby zapisać)" autocomplete="off" rows="3" maxlength="1000000"></textarea>
      <button type="button" class="modal-confirm save" id="notesAddBtn">Dodaj</button>
    </div>
    <div class="notes-list" id="notesList"></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="notesClearDone" style="margin-right:auto;">Usuń wykonane</button>
      <button type="button" class="modal-cancel" id="notesClose">Zamknij</button>
    </div>
  </div>
</div>
<div class="note-detail-backdrop" id="noteDetailBackdrop" role="dialog" aria-modal="true" aria-labelledby="noteDetailTitle">
  <div class="modal note-detail-modal">
    <div class="note-detail-head">
      <div><h2 id="noteDetailTitle" style="margin:0 0 5px;">Notatka</h2><div class="notes-progress" id="noteDetailDate"></div></div>
      <div class="note-detail-head-actions">
        <button type="button" class="modal-cancel" id="noteDetailCopy">Kopiuj notatkę</button>
        <button type="button" class="note-detail-doing" id="noteDetailDoing"><span class="dot" aria-hidden="true"></span><span>Pracuję nad tym</span></button>
        <button type="button" class="modal-cancel" id="noteDetailEdit">Edytuj</button>
      </div>
    </div>
    <div class="note-detail-body" id="noteDetailBody"></div>
    <textarea class="note-detail-editor" id="noteDetailEditor" maxlength="1000000" style="display:none;"></textarea>
    <div class="note-detail-actions">
      <div class="note-detail-left-actions">
        <label class="note-detail-done" id="noteDetailDoneLabel" title="Oznacz notatkę jako zrobioną">
          <input type="checkbox" id="noteDetailDone" aria-label="Oznacz notatkę jako zrobioną">
          <span class="done-copy">Oznacz jako zrobione</span>
          <span class="done-hint" aria-hidden="true">kliknij</span>
        </label>
        <button type="button" class="modal-cancel" id="noteDetailDelete">Usuń</button>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="modal-cancel" id="noteDetailCancel" style="display:none;">Anuluj</button>
        <button type="button" class="modal-confirm save" id="noteDetailSave" style="display:none;">Zapisz</button>
        <button type="button" class="modal-confirm save" id="noteDetailClose">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="blockedBackdrop" role="dialog" aria-modal="true" aria-labelledby="blockedTitle">
  <div class="modal blocked-modal">
    <div class="notes-head">
      <h2 id="blockedTitle">Zablokowane nazwy</h2>
      <span class="notes-progress" id="blockedProgress"></span>
    </div>
    <p class="notes-help">
      Login, którego tu nie ma, a które brzmi wulgarnie, zostanie odrzucone przy rejestracji.
      Porównanie nie rozróżnia wielkości liter i łapie też wyrazy zawarte w loginie.
      Usunięcie pozycji działa natychmiast — loginów, które już istnieją, to nie zmienia.
    </p>
    <div class="notes-add">
      <input type="text" id="blockedInput" placeholder="Nazwa, której nie wolno użyć…" maxlength="60" autocomplete="off">
      <button type="button" class="modal-confirm save" id="blockedAddBtn">Dodaj</button>
    </div>
    <div class="notes-list" id="blockedList"></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="blockedClose">Zamknij</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="wordsBackdrop" role="dialog" aria-modal="true" aria-labelledby="wordsTitle">
  <div class="modal blocked-modal">
    <div class="notes-head">
      <h2 id="wordsTitle">Wulgaryzmy w komentarzach</h2>
      <span class="notes-progress" id="wordsProgress"></span>
    </div>
    <p class="notes-help">
      Komentarz zawierający któryś z tych wyrazów nie zostanie dodany — ani treść,
      ani (przy komentarzu anonimowym) nazwa autora. Porównanie nie rozróżnia
      wielkości liter ani polskich znaków i łapie zapisy typu „ch@j” albo
      rozdzielone literami „k u r w a”.
      Wyrazy 3–4 literowe działają tylko na <b>początku</b> słowa — dzięki temu
      „dupa” łapie „dupeczkę”, a „podupadły” zostaje; dłuższe działają wszędzie.
      Gdy któryś wyraz zacznie blokować zwykłe słowa, usuń go tutaj — efekt jest
      natychmiastowy.
    </p>
    <div class="notes-add">
      <input type="text" id="wordsInput" placeholder="Wyraz, którego nie wolno użyć…" maxlength="60" autocomplete="off">
      <button type="button" class="modal-confirm save" id="wordsAddBtn">Dodaj</button>
    </div>
    <div class="notes-list" id="wordsList"></div>
    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="wordsClose">Zamknij</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="reportsBackdrop" role="dialog" aria-modal="true" aria-labelledby="reportsTitle">
  <div class="modal blocked-modal reports-modal">
    <div class="notes-head">
      <h2 id="reportsTitle">Zgłoszenia</h2>
      <span class="notes-progress" id="reportsProgress"></span>
    </div>

    <div class="notify-tabs" role="tablist">
      <button class="notify-tab is-on" type="button" role="tab" aria-selected="true" data-rtab="comments">Komentarze<span class="tab-count" id="reportsCommentsBadge">0</span></button>
      <button class="notify-tab" type="button" role="tab" aria-selected="false" data-rtab="photos">Zdjęcia<span class="tab-count" id="reportsPhotosBadge">0</span></button>
    </div>

    <div class="reports-pane" id="reportsPaneComments" data-rpane="comments">
      <p class="notes-help">
        Zgłoszenia komentarzy od użytkowników i od osób niezarejestrowanych.
        <b>Pokaż</b> otwiera zdjęcie z otwartymi komentarzami i przewijaniem do
        zgłoszonego wpisu. <b>Usuń komentarz</b> kasuje komentarz razem z odpowiedziami,
        <b>odrzuć</b> zamyka zgłoszenie bez zmian w komentarzu.
      </p>
      <div class="notes-list" id="reportsList"></div>
    </div>

    <div class="reports-pane" id="reportsPanePhotos" data-rpane="photos" hidden>
      <p class="notes-help">
        Zgłoszenia zdjęć z galerii (także od osób niezarejestrowanych).
        <b>Pokaż</b> otwiera zdjęcie w lightboxie, <b>Usuń zdjęcie</b> kasuje je
        razem z komentarzami i polubieniami, <b>odrzuć</b> zamyka zgłoszenie bez
        zmian w zdjęciu.
      </p>
      <div class="notes-list" id="reportsPhotosList"></div>
    </div>

    <div class="modal-actions">
      <button type="button" class="modal-cancel" id="reportsClose">Zamknij</button>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="notifyBackdrop" role="dialog" aria-modal="true" aria-labelledby="notifyTitle">
  <div class="modal notify-modal">
    <div class="notes-head">
      <h2 id="notifyTitle">Powiadomienia</h2>
      <span class="notes-progress" id="notifyProgress"></span>
    </div>

    <div class="notify-tabs" role="tablist">
      <button class="notify-tab is-on" type="button" role="tab" aria-selected="true" data-ntab="alerts">Alerty</button>
      <button class="notify-tab" type="button" role="tab" aria-selected="false" data-ntab="config">Konfiguracja typów</button>
    </div>

    <!-- ============================================ ALERTY -->
    <div class="notify-pane" id="notifyPaneAlerts" data-npane="alerts">
      <p class="notes-help">
        Zdarzenia, które wymagają Twojej reakcji: nowe konta, zgłoszenia do moderacji,
        zgłoszone komentarze i nowe wnioski o kategorię.
        <b>Obsłużone</b> oznacza alert jako przeczytany, <b>Usuń</b> kasuje go na zawsze.
      </p>
      <div class="notes-list" id="notifyAlertList"></div>
      <div class="modal-actions">
        <button type="button" class="modal-cancel" id="notifyReadAll" style="margin-right:auto;">oznacz wszystkie jako przeczytane</button>
        <button type="button" class="modal-cancel" id="notifyClearHandled">usuń obsłużone</button>
        <button type="button" class="modal-cancel" id="notifyCloseAlerts">Zamknij</button>
      </div>
    </div>

    <!-- ======================================= KONFIGURACJA -->
    <div class="notify-pane" id="notifyPaneConfig" data-npane="config" hidden>
      <p class="notes-help">
        Każdy typ powiadomienia osobno: <b>włączony</b> decyduje, czy w ogóle działa,
        a trzy pola pod spodem mówią, komu ma trafić — właścicielowi obiektu,
        obserwującym albo Tobie (alerty w panelu). Ustawienia per użytkownik
        działają tylko dla typów, które tu masz włączone i skierowane do użytkownika —
        powiadomienia o decyzjach administracji (moderacja, rangi) użytkownik
        nie może sobie wyłączyć.
      </p>
      <div class="notes-list" id="notifyCfgList" style="max-height:44vh"></div>

      <div class="notify-broadcast">
        <textarea id="notifyBroadcastText" maxlength="300" placeholder="Ogłoszenie dla wszystkich użytkowników (maks. 300 znaków)…" aria-label="Treść ogłoszenia"></textarea>
        <button type="button" class="modal-confirm save" id="notifyBroadcastBtn">wyślij do wszystkich</button>
      </div>
      <p class="notes-help" style="margin:8px 0 0" id="notifyBroadcastInfo">
        Użytkownicy, którzy mają ten typ wyłączony, ogłoszenia nie dostaną.
      </p>

      <div class="modal-actions">
        <button type="button" class="modal-cancel" id="notifyCloseConfig">Zamknij</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
(function(){
  const API = 'comments.php';
  const PAPI = 'admin-api.php';
  const el = id => document.getElementById(id);
  const escapeHtml = str => String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  let offset = 0;
  let totalCount = 0;
  let currentSearch = '';
  let currentSort = 'newest';
  let currentPeriod = '';
  let currentPhotoId = '';
  let loadingMore = false;
  let selected = new Set();
  let sessionExp = null;
  let csrfToken = '';
  const PAGE_SIZE = 30;
  const itemsById = new Map();

  function toast(msg){
    const t = el('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => t.classList.remove('show'), 2600);
  }

  function formatDate(ts){
    try{
      return new Date(ts).toLocaleString('pl-PL', {
        day:'2-digit', month:'2-digit', year:'numeric',
        hour:'2-digit', minute:'2-digit'
      });
    }catch(_){ return ''; }
  }

  // Link otwiera zdjęcie na stronie głównej razem z panelem komentarzy; przy podanym
  // commentId ten komentarz zostaje przewinięty na środek i podświetlony.
  function photoLink(photoId, commentId){
    if(!photoId) return null;
    let url = `index.php?photo=${encodeURIComponent(photoId)}`;
    if(commentId) url += `&comment=${encodeURIComponent(commentId)}`;
    return url;
  }

  function updateBulkBar(){
    const n = selected.size;
    el('bulkCount').textContent = n;
    el('bulkBar').classList.toggle('open', n > 0);
  }

  function updateSelectAllState(){
    const boxes = [...document.querySelectorAll('.admin-check')];
    const all = boxes.length > 0 && boxes.every(b => b.checked);
    el('selectAll').checked = all;
  }

  async function whoAmI(){
    try{
      const res = await fetch(`${API}?action=admin_whoami`, { credentials:'include' });
      const data = await res.json();
      sessionExp = data.exp || null;
      csrfToken = data.csrf || '';
      return data.user || null;
    }catch(_){ return null; }
  }

  function showLoggedOut(msg){
    window.zmAdminUser = '';
    el('adminPanel').style.display = 'none';
    el('logoutBtn').style.display = 'none';
    el('loginScreen').style.display = 'flex';
    el('loginError').textContent = msg || '';
    el('loginError').style.display = msg ? 'block' : 'none';
    el('loginPass').value = '';
    el('loginSubmit').disabled = false;
    el('loginSubmit').textContent = 'Zaloguj';
    selected.clear();
    updateBulkBar();
    if(window.zmPhotos) window.zmPhotos.stop();
    if(window.zmNotes) window.zmNotes.stop();
    if(window.zmProjects) window.zmProjects.stop();
  }

  function showLoggedIn(user){
    window.zmAdminUser = user || '';   // users (IIFE) czyta to przy otwieraniu szczegółów
    el('loginScreen').style.display = 'none';
    el('adminPanel').style.display = 'block';
    el('logoutBtn').style.display = 'inline-block';
    el('adminUser').textContent = user;
    if(sessionExp){
      el('adminSession').textContent = '· sesja do ' + formatDate(sessionExp * 1000);
    } else {
      el('adminSession').textContent = '';
    }
    loadStats();
    loadComments(true);
    loadLog();
    if(window.zmPhotos) window.zmPhotos.start(csrfToken);
    if(window.zmNotes) window.zmNotes.start();
    if(window.zmProjects) window.zmProjects.start();
    // Powiadomienia (alerty) — plakietka na przycisku, tak jak Notatnik
    if(window.zmNotify) window.zmNotify.start();
    // Przy przeładowaniu panel wraca do ostatnio otwartej głównej zakładki.
    let savedView = 'comments';
    try{ savedView = sessionStorage.getItem('zm-admin-active-view') || 'comments'; }catch(_){}
    if(['comments','photos','projects','attraction','links','users','traffic','security','maintenance','settings'].includes(savedView) && savedView !== 'comments'){
      switchView(savedView);
    }
  }

  el('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    el('loginError').style.display = 'none';
    const user = el('loginUser').value.trim();
    const pass = el('loginPass').value;
    if(!user || !pass){
      showLoggedOut('Podaj login i hasło.');
      return;
    }
    el('loginSubmit').disabled = true;
    el('loginSubmit').textContent = 'Logowanie…';
    try{
      const res = await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'admin_login', user, pass })
      });
      const data = await res.json();
      if(res.status === 429 || data.error === 'locked'){
        const sec = data.retry_after || 600;
        const min = Math.ceil(sec / 60);
        showLoggedOut(`Zbyt wiele nieudanych prób. Spróbuj za ${min} min.`);
        return;
      }
      if(!res.ok || !data.ok){
        showLoggedOut(
          data && data.error === 'bad_credentials'
            ? 'Nieprawidłowy login lub hasło.'
            : 'Nie udało się zalogować. Spróbuj ponownie.'
        );
        return;
      }
      sessionExp = data.exp || null;
      csrfToken = data.csrf || '';
      showLoggedIn(data.user);
    }catch(_){
      showLoggedOut('Błąd połączenia z serwerem.');
    }
  });

  async function loadStats(){
    try{
      const res = await fetch(`${API}?action=admin_stats`, { credentials:'include' });
      if(res.status === 401){ showLoggedOut('Sesja wygasła — zaloguj się ponownie.'); return; }
      if(!res.ok){
        el('statTotal').textContent = '—'; el('statToday').textContent = '—'; el('statWeek').textContent = '—';
        return;
      }
      const data = await res.json();
      if(data.error){
        el('statTotal').textContent = '—'; el('statToday').textContent = '—'; el('statWeek').textContent = '—';
        return;
      }
      el('statTotal').textContent = data.total ?? 0;
      el('statToday').textContent = data.today ?? 0;
      el('statWeek').textContent = data.week ?? 0;
      const top = data.top_photos || [];
      const box = el('topPhotos');
      const list = el('topPhotosList');
      if(!top.length){
        box.style.display = 'none';
        list.innerHTML = '';
        return;
      }
      box.style.display = 'block';
      list.innerHTML = top.map(t => `
        <div class="top-photo-row" data-photo-id="${escapeHtml(t.photo_id || '')}"${t.photo_live ? '' : ' data-gone="1" title="Zdjęcie usunięte lub wycofane — komentarze zostają w bazie"'}>
          ${t.photo_live && t.photo_thumb ? `<img src="${escapeHtml(t.photo_thumb)}" alt="" loading="lazy">` : '<div style="width:36px;height:28px;border:1px dashed var(--line-strong);border-radius:4px;"></div>'}
          <span>${escapeHtml(t.photo_name || t.photo_id || '—')}</span>
          <span class="cnt">${t.cnt}</span>
        </div>
      `).join('');
      list.querySelectorAll('.top-photo-row').forEach(row => {
        row.addEventListener('click', () => {
          currentPhotoId = row.dataset.photoId || '';
          updatePhotoChip();
          loadComments(true);
        });
      });
    }catch(_){}
  }

  function updatePhotoChip(){
    const chip = el('photoFilterChip');
    if(currentPhotoId){
      chip.classList.add('open');
      el('photoFilterLabel').textContent = 'zdjęcie: ' + (currentPhotoId.length > 18 ? currentPhotoId.slice(0,16)+'…' : currentPhotoId);
    } else {
      chip.classList.remove('open');
      el('photoFilterLabel').textContent = '';
    }
  }

  el('photoFilterClear').addEventListener('click', () => {
    currentPhotoId = '';
    updatePhotoChip();
    loadComments(true);
  });

  async function loadComments(reset){
    if(loadingMore) return;
    loadingMore = true;
    if(reset){
      offset = 0;
      el('commentsList').innerHTML = '';
      selected.clear();
      itemsById.clear();
      updateBulkBar();
      el('selectAll').checked = false;
    }
    const btn = el('loadMoreBtn');
    btn.disabled = true;
    btn.textContent = 'Wczytywanie…';
    try{
      const params = new URLSearchParams({
        action:'admin_list',
        limit:String(PAGE_SIZE),
        offset:String(offset),
        search:currentSearch,
        sort:currentSort,
        period:currentPeriod,
        photo_id:currentPhotoId
      });
      const res = await fetch(`${API}?${params.toString()}`, { credentials:'include' });
      if(res.status === 401){ showLoggedOut('Sesja wygasła — zaloguj się ponownie.'); return; }
      const data = await res.json();
      totalCount = data.total || 0;
      renderItems(data.items || [], reset);
      offset += (data.items || []).length;
      el('adminCount').textContent = totalCount;
      btn.style.display = offset < totalCount ? 'block' : 'none';
    }catch(_){
      if(reset) el('commentsList').innerHTML = '<div class="admin-error">Nie udało się wczytać komentarzy.</div>';
    }finally{
      loadingMore = false;
      btn.disabled = false;
      btn.textContent = 'Wczytaj więcej';
    }
  }

  function renderItems(items, reset){
    const list = el('commentsList');
    if(reset && !items.length){
      list.innerHTML = '<div class="admin-empty">Brak komentarzy' + (currentSearch || currentPeriod || currentPhotoId ? ' dla tych filtrów' : '') + '.</div>';
      return;
    }
    const html = items.map(c => {
      itemsById.set(c.id, c);
      const checked = selected.has(c.id) ? 'checked' : '';
      const selClass = selected.has(c.id) ? ' selected' : '';
      const dlink = photoLink(c.photo_id, c.id);
      const photoLabel = escapeHtml(c.photo_name || c.photo_id || '');
      const photoHtml = dlink
        ? `<a href="${escapeHtml(dlink)}" target="_blank" rel="noopener">${photoLabel}</a>`
        : photoLabel;
      const svg = d => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${d}</svg>`;
      const ico = {
        heart: svg('<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"/>'),
        reply: svg('<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>'),
        photo: svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>')
      };
      const thumbBox = c.photo_thumb
        ? `<img class="admin-thumb admin-thumb-photo" src="${escapeHtml(c.photo_thumb)}" alt="" loading="lazy">`
        : '<div class="admin-thumb admin-thumb-photo admin-thumb-empty"></div>';
      const thumbHtml = dlink && c.photo_thumb
        ? `<a class="admin-thumb-link" href="${escapeHtml(dlink)}" target="_blank" rel="noopener" title="Otwórz zdjęcie z tym komentarzem">${thumbBox}</a>`
        : thumbBox;
      const chips = `<div class="admin-stats">
            <span class="admin-chip" title="Polubienia komentarza">${ico.heart} ${c.likes || 0}</span>
            ${c.parent_id ? `<span class="admin-chip" title="To jest odpowiedź">${ico.reply} odpowiedź${c.reply_to ? ' do: ' + escapeHtml(c.reply_to) : ''}</span>` : ''}
            ${dlink ? `<a class="admin-chip loc-yes" href="${escapeHtml(dlink)}" target="_blank" rel="noopener" title="Otwórz zdjęcie z podświetlonym komentarzem">${ico.photo} zdjęcie</a>` : ''}
          </div>`;
      return `
      <div class="admin-item${selClass}${c.parent_id ? ' is-reply' : ''}" data-id="${c.id}">
        <input type="checkbox" class="admin-check" data-id="${c.id}" ${checked} aria-label="Zaznacz">
        ${thumbHtml}
        <div class="admin-item-body">
          <div class="admin-item-head">
            ${dlink
              ? `<a class="admin-author admin-title-link" href="${escapeHtml(dlink)}" target="_blank" rel="noopener" title="Otwórz zdjęcie z tym komentarzem">${escapeHtml(c.author || 'Anonim')}</a>`
              : `<span class="admin-author">${escapeHtml(c.author || 'Anonim')}</span>`}
            <span class="admin-date">${formatDate(c.ts)}</span>
          </div>
          <div class="admin-photo-name">${photoHtml}</div>
          <div class="admin-text">${escapeHtml(c.text)}</div>
          ${chips}
        </div>
        <div class="admin-item-actions">
          ${dlink ? `<a class="admin-edit-btn" href="${escapeHtml(dlink)}" target="_blank" rel="noopener">Otwórz</a>` : ''}
          <button type="button" class="admin-del-btn" data-id="${c.id}">Usuń</button>
        </div>
      </div>`;
    }).join('');
    list.insertAdjacentHTML('beforeend', html);

    list.querySelectorAll('.admin-check').forEach(box => {
      if(box._bound) return;
      box._bound = true;
      box.addEventListener('change', () => {
        const id = +box.dataset.id;
        if(box.checked) selected.add(id); else selected.delete(id);
        const item = document.querySelector(`.admin-item[data-id="${id}"]`);
        if(item) item.classList.toggle('selected', box.checked);
        updateBulkBar();
        updateSelectAllState();
      });
    });
    list.querySelectorAll('.admin-del-btn').forEach(btn => {
      if(btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', () => openDeleteModal(+btn.dataset.id));
    });
    list.querySelectorAll('.admin-thumb[data-drive]').forEach(img => {
      if(img._bound || !img.dataset.drive) return;
      img._bound = true;
      img.addEventListener('click', () => window.open(img.dataset.drive, '_blank', 'noopener'));
    });
    updateSelectAllState();
  }

  let modalMode = null;
  let modalTargetId = null;

  function closeModal(){
    el('modalBackdrop').classList.remove('open');
    modalMode = null;
    modalTargetId = null;
  }

  function openDeleteModal(id){
    const c = itemsById.get(id);
    if(!c) return;
    modalMode = 'delete';
    modalTargetId = id;
    el('modalTitle').textContent = 'Usunąć komentarz?';
    el('modalPreview').style.display = 'block';
    el('modalPreview').innerHTML = `<div class="who">${escapeHtml(c.author || 'Anonim')} · ${formatDate(c.ts)}</div>${escapeHtml(c.text)}`;
    el('modalConfirm').textContent = 'Usuń na stałe';
    el('modalConfirm').className = 'modal-confirm';
    el('modalBackdrop').classList.add('open');
  }

  function openBulkDeleteModal(){
    if(!selected.size) return;
    modalMode = 'delete_bulk';
    modalTargetId = null;
    el('modalTitle').textContent = `Usunąć ${selected.size} komentarzy?`;
    el('modalPreview').style.display = 'block';
    el('modalPreview').textContent = 'Tej operacji nie da się cofnąć.';
    el('modalConfirm').textContent = 'Usuń zaznaczone';
    el('modalConfirm').className = 'modal-confirm';
    el('modalBackdrop').classList.add('open');
  }

  el('modalCancel').addEventListener('click', closeModal);
  el('modalBackdrop').addEventListener('click', e => { if(e.target === el('modalBackdrop')) closeModal(); });
  document.addEventListener('keydown', e => {
    if(e.key === 'Escape' && el('modalBackdrop').classList.contains('open')) closeModal();
  });

  el('modalConfirm').addEventListener('click', async () => {
    const btn = el('modalConfirm');
    btn.disabled = true;
    try{
      if(modalMode === 'delete'){
        await doDelete(modalTargetId);
      } else if(modalMode === 'delete_bulk'){
        await doBulkDelete();
      }
      closeModal();
    } finally {
      btn.disabled = false;
    }
  });

  async function doDelete(id){
    try{
      const res = await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'admin_delete', id, csrf: csrfToken })
      });
      if(res.status === 401){ showLoggedOut('Sesja wygasła — zaloguj się ponownie.'); return; }
      const data = await res.json();
      if(data.ok && data.removed > 1){
        // usunięto też odpowiedzi do komentarza — lista musi się przeładować
        selected.delete(id);
        updateBulkBar();
        toast(`Usunięto komentarz i odpowiedzi (${data.removed - 1})`);
        loadComments(true);
        loadStats();
        loadLog();
      } else if(data.ok){
        const item = document.querySelector(`.admin-item[data-id="${id}"]`);
        if(item) item.remove();
        itemsById.delete(id);
        selected.delete(id);
        totalCount = Math.max(0, totalCount - 1);
        el('adminCount').textContent = totalCount;
        updateBulkBar();
        if(!document.querySelector('.admin-item')) el('commentsList').innerHTML = '<div class="admin-empty">Brak komentarzy.</div>';
        toast('Usunięto komentarz');
        loadStats();
        loadLog();
      } else {
        toast('Nie udało się usunąć');
      }
    }catch(_){
      toast('Błąd połączenia');
    }
  }

  async function doBulkDelete(){
    const ids = [...selected];
    if(!ids.length) return;
    try{
      const res = await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'admin_delete_bulk', ids, csrf: csrfToken })
      });
      if(res.status === 401){ showLoggedOut('Sesja wygasła — zaloguj się ponownie.'); return; }
      const data = await res.json();
      const n = data.deleted || 0;
      ids.forEach(id => {
        const item = document.querySelector(`.admin-item[data-id="${id}"]`);
        if(item) item.remove();
        itemsById.delete(id);
        selected.delete(id);
      });
      totalCount = Math.max(0, totalCount - n);
      el('adminCount').textContent = totalCount;
      updateBulkBar();
      el('selectAll').checked = false;
      if(!document.querySelector('.admin-item')) el('commentsList').innerHTML = '<div class="admin-empty">Brak komentarzy.</div>';
      toast(`Usunięto: ${n}`);
      loadStats();
      loadLog();
    }catch(_){
      toast('Błąd połączenia');
    }
  }

  async function loadLog(){
    try{
      const res = await fetch(`${API}?action=admin_log&limit=20`, { credentials:'include' });
      if(!res.ok) return;
      const data = await res.json();
      const items = data.items || [];
      const list = el('logList');
      if(!items.length){
        list.innerHTML = '<div class="admin-empty" style="padding:16px 0;">Brak wpisów.</div>';
        return;
      }
      list.innerHTML = items.map(l => `
        <div class="log-item">
          <span class="log-action">${escapeHtml(l.action)}</span>
          · ${formatDate(l.ts)}
          · <b>${escapeHtml(l.admin_user || '')}</b>
          ${l.author ? ` · ${escapeHtml(l.author)}` : ''}
          ${l.text ? `<div style="margin-top:4px;color:var(--ink);">${escapeHtml((l.text || '').slice(0,120))}${(l.text||'').length>120?'…':''}</div>` : ''}
        </div>
      `).join('');
    }catch(_){}
  }

  let searchDebounce;
  el('searchInput').addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
      currentSearch = el('searchInput').value.trim();
      loadComments(true);
    }, 300);
  });

  el('sortSelect').addEventListener('change', () => {
    currentSort = el('sortSelect').value;
    loadComments(true);
  });
  el('periodSelect').addEventListener('change', () => {
    currentPeriod = el('periodSelect').value;
    loadComments(true);
  });

  el('loadMoreBtn').addEventListener('click', () => loadComments(false));
  window.addEventListener('pageshow', (e) => {
    currentSort = 'newest';
    const s = el('sortSelect');
    if (s) s.value = 'newest';
    if (typeof loadComments === 'function') loadComments(true);
  });
  el('refreshBtn').addEventListener('click', () => {
    loadStats();
    loadComments(true);
    loadLog();
    toast('Odświeżono');
  });

  el('selectAll').addEventListener('change', () => {
    const on = el('selectAll').checked;
    document.querySelectorAll('.admin-check').forEach(box => {
      box.checked = on;
      const id = +box.dataset.id;
      if(on) selected.add(id); else selected.delete(id);
      const item = document.querySelector(`.admin-item[data-id="${id}"]`);
      if(item) item.classList.toggle('selected', on);
    });
    updateBulkBar();
  });

  el('bulkClear').addEventListener('click', () => {
    selected.clear();
    document.querySelectorAll('.admin-check').forEach(b => { b.checked = false; });
    document.querySelectorAll('.admin-item').forEach(i => i.classList.remove('selected'));
    el('selectAll').checked = false;
    updateBulkBar();
  });

  el('bulkDelete').addEventListener('click', openBulkDeleteModal);

  el('logoutBtn').addEventListener('click', async () => {
    try{
      await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ action:'admin_logout', csrf: csrfToken })
      });
    }catch(_){}
    showLoggedOut('');
    toast('Wylogowano');
  });

  el('tabComments').addEventListener('click', () => switchView('comments'));
  el('tabPhotos').addEventListener('click', () => switchView('photos'));
  el('tabProjects').addEventListener('click', () => switchView('projects'));
  el('tabAttraction').addEventListener('click', () => switchView('attraction'));
  el('tabUsers').addEventListener('click', () => switchView('users'));
  el('tabTraffic').addEventListener('click', () => switchView('traffic'));
  el('tabSecurity').addEventListener('click', () => switchView('security'));
  el('tabMaintenance').addEventListener('click', () => switchView('maintenance'));
  el('tabSettings').addEventListener('click', () => switchView('settings'));
  el('tabLinks').addEventListener('click', () => { switchView('links'); if(window.zmLinks) window.zmLinks.refresh(); });

  function switchView(which){
    try{ sessionStorage.setItem('zm-admin-active-view', which); }catch(_){}
    const views = { comments:'commentsView', photos:'photosView', projects:'projectsView', attraction:'attractionView', links:'linksView', users:'usersView', traffic:'trafficView', security:'securityView', maintenance:'maintenanceView', settings:'settingsView' };
    const tabs = { comments:'tabComments', photos:'tabPhotos', projects:'tabProjects', attraction:'tabAttraction', links:'tabLinks', users:'tabUsers', traffic:'tabTraffic', security:'tabSecurity', maintenance:'tabMaintenance', settings:'tabSettings' };
    // Przy przejściu na inną zakładkę czyścimy filtry pozostałych widoków,
    // żeby powrót (np. Zdjęcia → Komentarze → Zdjęcia) nie przywoływał
    // poprzednio wybranego filtru (np. „Atrakcje”). Aktywny moduł i tak jest
    // odświeżany poniżej i przeczyta już wyczyszczone pola. Czyścimy tylko
    // inputy/selecty — przyciski typu „+ Dodaj” / „Kategorie” zostają.
    Object.keys(views).forEach(k => {
      if(k === which) return;
      const panel = el(views[k]);
      if(!panel) return;
      panel.querySelectorAll('.filters-row input[type=text], .filters-row input[type=search], .filters-row input[type=number]').forEach(i => { i.value = ''; });
      // Selecty wracają do wartości domyślnej z HTML (atrybut selected), a gdy
      // jej nie ma (opcje wstrzykiwane dynamicznie) — do pierwszej opcji.
      panel.querySelectorAll('.filters-row select').forEach(s => {
        if(!s.options.length) return;
        let idx = 0;
        for(let i = 0; i < s.options.length; i++){ if(s.options[i].defaultSelected){ idx = i; break; } }
        s.selectedIndex = idx;
      });
    });
    Object.keys(views).forEach(k => {
      const v = el(views[k]);
      if(v) v.style.display = (k === which) ? 'block' : 'none';
      const t = el(tabs[k]);
      if(t) t.classList.toggle('active', k === which);
    });

    if (which === 'comments') {
      currentSort = 'newest';
      const ss = el('sortSelect');
      if (ss) ss.value = 'newest';
      loadComments(true);
    }
    if(which === 'projects' && window.zmProjects) window.zmProjects.refresh();
    if(which === 'attraction' && window.zmAttraction) window.zmAttraction.refresh();
    if(which === 'users' && window.zmUsers) window.zmUsers.refresh();
    if(which === 'traffic' && window.zmTraffic) window.zmTraffic.refresh();
    if(which === 'security' && window.zmSecurity) window.zmSecurity.refresh();
    if(which === 'maintenance' && window.zmMaintenance) window.zmMaintenance.refresh();
    if(which === 'settings' && window.zmSettings) window.zmSettings.refresh();
    // lista komentarzy też bywa nieaktualna: po rename albo usunięciu konta
    // autorzy w niej mają stare nazwy, a widok nigdy nie był odświeżany
    if(which === 'comments') window.zmComments.refresh();
  }

  el('refreshBtn').addEventListener('click', () => {
    if(el('photosView').style.display !== 'none' && window.zmPhotos) window.zmPhotos.refresh();
    else if(el('projectsView').style.display !== 'none' && window.zmProjects) window.zmProjects.refresh();
    else if(el('attractionView').style.display !== 'none' && window.zmAttraction) window.zmAttraction.refresh();
    else if(el('usersView').style.display !== 'none' && window.zmUsers) window.zmUsers.refresh();
    else if(el('trafficView').style.display !== 'none' && window.zmTraffic) window.zmTraffic.refresh();
    else if(el('securityView').style.display !== 'none' && window.zmSecurity) window.zmSecurity.refresh();
    else if(el('maintenanceView').style.display !== 'none' && window.zmMaintenance) window.zmMaintenance.refresh();
    else if(el('settingsView').style.display !== 'none'){ /* schemat — nie ma czego odświeżać */ }
    else if(el('linksView').style.display !== 'none' && window.zmLinks) window.zmLinks.refresh();
    else window.zmComments.refresh();
  });

  window.zmToast = toast;
  window.zmGetCsrf = () => csrfToken;
  window.zmSessionLost = () => showLoggedOut('Sesja wygasła — zaloguj się ponownie.');
  window.zmComments = {
    refresh(){
      loadStats();
      // ukrytej listy nie ma po co odświeżać — wróci do stanu sprzed zmiany
      if(el('commentsView').style.display !== 'none') loadComments(true);
    }
  };

  (async function init(){
    const user = await whoAmI();
    if(user){ showLoggedIn(user); }
    else{ showLoggedOut(''); }
  })();
})();
</script>

<script>
/* ------------------------------------------------------------------
   Zarządzanie zdjęciami — dodatkowy moduł panelu.
   To jest właściwy "moduł zdjęć" w admin.php.
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = str => String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);

  // Pole lokalizacji przyjmuje link do miejsca z Google Maps. Nie wyciągamy
  // współrzędnych w przeglądarce — link leci na serwer, który odczytuje je
  // z URL-a (config.php, zm_google_maps_coords) i zapisuje w photos.lat/lng.
  // Funkcja poniżej tylko wstępnie sprawdza, że to wygląda na adres WWW,
  // żeby nie wysyłać oczywistej śmieci; właściwą walidację robi serwer.
  function normalizeMapsLink(raw){
    if(typeof raw !== 'string') return '';
    const s = raw.trim();
    if(!s) return '';
    if(!/^https?:\/\/[^\s]+$/i.test(s)) return null;
    return s;
  }

  // Odwrotność powyższego: zapisane współrzędne -> link Google Maps.
  // Ten sam format co zm_google_maps_link() w config.php, żeby ponowne
  // zapisanie edycji nie kasowało lokalizacji i dawało te same wyniki.
  function makeGoogleLinkFromCoords(lat, lng){
    if(lat === null || lat === undefined || lng === null || lng === undefined) return '';
    const a = Number(lat), b = Number(lng);
    if(!isFinite(a) || !isFinite(b)) return '';
    if(a === 0 && b === 0) return '';
    return 'https://www.google.com/maps/search/?api=1&query='
      + encodeURIComponent(`${a.toFixed(7)},${b.toFixed(7)}`);
  }

  function makeMapUrlFromCoords(lat, lng){
    if(lat == null || lng == null) return '';
    const latNum = Number(lat);
    const lngNum = Number(lng);
    // 0,0 to Zatoka Gwinejska, nie Polska — bez tego warunku zdjęcie bez
    // lokalizacji dostałoby mapę w środku oceanu. makeGoogleLinkFromCoords()
    // ma tę samą ochronę, tutaj brakowało.
    if(!isFinite(latNum) || !isFinite(lngNum)) return '';
    if(latNum === 0 && lngNum === 0) return '';
    return `https://www.openstreetmap.org/export/embed.html?bbox=${(lngNum - 0.01).toFixed(5)}%2C${(latNum - 0.01).toFixed(5)}%2C${(lngNum + 0.01).toFixed(5)}%2C${(latNum + 0.01).toFixed(5)}&layer=mapnik&marker=${latNum.toFixed(5)}%2C${lngNum.toFixed(5)}`;
  }

  const PAGE = 24;

  // "2026-10-01 14:30:00" → "01.10.2026 14:30"
  function fmtSchedule(v){
    const m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(String(v || ''));
    return m ? `${m[3]}.${m[2]}.${m[1]} ${m[4]}:${m[5]}` : String(v || '');
  }
  function toLocalInput(v){
    const m = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/.exec(String(v || ''));
    return m ? `${m[1]}T${m[2]}` : '';
  }
  function nowLocalInput(){
    const d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    return d.toISOString().slice(0, 16);
  }
  function bindSchedule(checkId, boxId, inputId){
    const chk = el(checkId), box = el(boxId), inp = el(inputId);
    const sync = () => {
      box.style.display = chk.checked ? 'none' : 'block';
      inp.min = nowLocalInput();
    };
    chk.addEventListener('change', sync);
    return sync;
  }
  const syncUpSchedule = bindSchedule('phUpPublished', 'phUpScheduleBox', 'phUpPublishAt');
  const syncEdSchedule = bindSchedule('phEdPublished', 'phEdScheduleBox', 'phEdPublishAt');
  let csrf = '';
  let offset = 0, total = 0, busy = false, started = false;
  let categories = [];
  // katalog etykiet atrakcji (klucz => nazwa) z photo_list; panel buduje z niego
  // listę „dodaj etykietę” w oknie moderacji
  let attrCatalog = {};
  let editing = null;
  const byId = new Map();
  const phSelected = new Set();
  let rejectingPhoto = null;
  let rejectingPhotoButton = null;
  let rejectSubmitting = false;

  function syncPhotoRejectForm(){
    const custom = el('photoRejectReason').value === 'inne';
    el('photoRejectCustom').hidden = !custom;
    const hasReason = !!el('photoRejectReason').value;
    const hasCustomText = el('photoRejectMessage').value.trim() !== '';
    el('photoRejectConfirm').disabled = !hasReason || (custom && !hasCustomText) || rejectSubmitting;
  }

  function openPhotoRejectForm(photo, button){
    rejectingPhoto = photo;
    rejectingPhotoButton = button;
    el('photoRejectPhoto').textContent = photo.title || photo.filename || 'Zgłoszone zdjęcie';
    el('photoRejectReason').value = '';
    el('photoRejectMessage').value = '';
    el('photoRejectError').textContent = '';
    el('photoRejectError').style.display = 'none';
    rejectSubmitting = false;
    el('photoRejectCancel').disabled = false;
    el('photoRejectConfirm').textContent = 'Odrzuć zdjęcie';
    syncPhotoRejectForm();
    el('photoRejectBackdrop').classList.add('open');
    el('photoRejectReason').focus();
  }

  function closePhotoRejectForm(force = false){
    if(rejectSubmitting && !force) return;
    el('photoRejectBackdrop').classList.remove('open');
    rejectingPhoto = null;
    rejectingPhotoButton = null;
  }

  async function submitPhotoReject(){
    const photo = rejectingPhoto;
    const button = rejectingPhotoButton;
    const reason = el('photoRejectReason').value;
    const custom = el('photoRejectMessage').value.trim();
    if(!photo || !button || !reason || (reason === 'inne' && !custom)) return;

    rejectSubmitting = true;
    el('photoRejectCancel').disabled = true;
    el('photoRejectConfirm').textContent = 'Odrzucanie…';
    el('photoRejectError').textContent = '';
    el('photoRejectError').style.display = 'none';
    syncPhotoRejectForm();
    try{
      const d = await post({
        action:'photo_moderate', id:photo.id, decision:'reject',
        reject_reason:reason, reject_message:reason === 'inne' ? custom : ''
      });
      if(d && d.ok){
        button.closest('.admin-item')?.remove();
        byId.delete(photo.id);
        phSelected.delete(photo.id);
        updatePhBulkBar();
        updatePhSelectVisibleState();
        if(el('phPublished').value === 'pending'){
          total = Math.max(0, total - 1);
          offset = Math.max(0, offset - 1);
          el('phCount').textContent = total;
          el('phMoreBtn').style.display = offset < total ? 'block' : 'none';
        }
        rejectSubmitting = false;
        closePhotoRejectForm(true);
        toast(d.notification_sent
          ? 'Zdjęcie odrzucone — powód wysłano zgłaszającemu'
          : 'Zdjęcie odrzucone');
        loadStats();
      } else if(d){
        el('photoRejectError').textContent = d.message || 'Nie udało się odrzucić zdjęcia.';
        el('photoRejectError').style.display = 'block';
      }
    }catch(_){
      el('photoRejectError').textContent = 'Błąd połączenia. Spróbuj ponownie.';
      el('photoRejectError').style.display = 'block';
    }finally{
      rejectSubmitting = false;
      if(el('photoRejectBackdrop').classList.contains('open')){
        el('photoRejectCancel').disabled = false;
        el('photoRejectConfirm').textContent = 'Odrzuć zdjęcie';
        syncPhotoRejectForm();
      }
    }
  }

  el('photoRejectReason').addEventListener('change', syncPhotoRejectForm);
  el('photoRejectMessage').addEventListener('input', syncPhotoRejectForm);
  el('photoRejectCancel').addEventListener('click', () => closePhotoRejectForm());
  el('photoRejectConfirm').addEventListener('click', submitPhotoReject);
  el('photoRejectBackdrop').addEventListener('click', e => {
    if(e.target === el('photoRejectBackdrop')) closePhotoRejectForm();
  });
  document.addEventListener('keydown', e => {
    if(e.key === 'Escape' && el('photoRejectBackdrop').classList.contains('open')) closePhotoRejectForm();
  });

  function filterParams(){
    const pub = el('phPublished').value;
    const params = {
      search: el('phSearch').value.trim(),
      category: el('phCategory').value,
      user: el('phUser').value
    };
    if(pub === 'pending'){ params.moderation = 'pending'; }
    else if(pub === 'attractions'){ params.attractions = '1'; }
    else { params.published = pub; }
    return params;
  }

  function q(){
    const params = Object.assign(
      { action:'photo_list', limit:String(PAGE), offset:String(offset) },
      filterParams()
    );
    return new URLSearchParams(params).toString();
  }

  function updatePhBulkBar(){
    el('phBulkCount').textContent = phSelected.size;
    el('phBulkBar').classList.toggle('open', phSelected.size > 0);
  }
  function updatePhSelectVisibleState(){
    const boxes = [...document.querySelectorAll('.ph-select')];
    const all = boxes.length > 0 && boxes.every(b => b.checked);
    el('phSelectVisible').checked = all;
  }

  async function get(url){
    const res = await fetch(url, { credentials:'include' });
    if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
    return res.json();
  }

  async function post(payload){
    const res = await fetch(API, {
      method:'POST', credentials:'include',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify(Object.assign({ csrf }, payload))
    });
    if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
    return res.json();
  }

  async function loadStats(){
    const d = await get(`${API}?action=photo_stats`);
    if(!d) return;
    el('phTotal').textContent = d.total ?? 0;
    el('phPub').textContent = d.published ?? 0;
    el('phHidden').textContent = d.hidden ?? 0;
    el('phCats').textContent = d.categories ?? 0;
    const pending = d.pending ?? 0;
    el('phPending').textContent = pending;
    document.querySelector('[data-stat="pending"]').classList.toggle('has-pending', pending > 0);
    setPendingBadge(pending);
    if(d.submitters){
      const sel = el('phUser');
      const cur = sel.value;
      sel.innerHTML = '<option value="">Wszyscy zgłaszający</option>'
        + d.submitters.map(s => `<option value="${s.id}">${esc(s.username)}</option>`).join('');
      if(!d.submitters.some(s => String(s.id) === cur)) sel.value = '';
    }
    if(d.unanalyzed > 0) analyzeMissing();
  }

  // Plakietka „do zatwierdzenia” na przycisku Zdjęcia (jak licznik przy Notatniku)
  function setPendingBadge(n){
    const b = el('photosBadge');
    if(!b) return;
    b.textContent = n > 99 ? '99+' : n;
    b.classList.toggle('show', n > 0);
    const tab = el('tabPhotos');
    if(tab) tab.title = n > 0 ? `Zdjęcia — do zatwierdzenia: ${n}` : 'Zdjęcia — brak zgłoszeń do zatwierdzenia';
  }

  // Lekki polling: nowe zgłoszenia pojawiają się bez odświeżania strony
  let pendingTimer = null;
  async function pollPending(){
    if(!started || document.hidden) return;
    try{
      const d = await get(`${API}?action=photo_stats`);
      if(d && typeof d.pending === 'number'){
        setPendingBadge(d.pending);
        const card = document.querySelector('[data-stat="pending"]');
        if(card){
          card.classList.toggle('has-pending', d.pending > 0);
          el('phPending').textContent = d.pending;
        }
      }
    }catch(_){}
  }

  // Zdjęcia wgrane przed wprowadzeniem oceny modyfikacji (albo bez niej) dostają ją automatycznie.
  let analyzing = false;
  async function analyzeMissing(){
    if(analyzing) return;
    analyzing = true;
    let done = 0;
    try{
      for(let i = 0; i < 30; i++){
        const r = await post({ action:'photo_analyze' });
        if(!r || !r.ok) break;
        done += r.analyzed || 0;
        if(!r.left || !r.analyzed) break;
      }
    } finally { analyzing = false; }
    if(done > 0){
      toast(`Sprawdzono modyfikacje zdjęć: ${done}`);
      loadList(true);
    }
  }

  let catMaxDepth = 4;
  const catById = () => new Map(categories.map(c => [c.id, c]));

  // czy kategoria `id` leży (dowolnie głęboko) w kategorii `ancestorId`
  function catInside(id, ancestorId){
    const map = catById();
    let cur = map.get(id), guard = 0;
    while(cur && cur.parent_id && guard++ < 12){
      if(cur.parent_id === ancestorId) return true;
      cur = map.get(cur.parent_id);
    }
    return false;
  }

  function fillParentSelect(selected){
    const editingCat = catEditing !== null ? categories.find(c => c.id === catEditing) : null;
    const ownHeight = editingCat ? (editingCat.height || 1) : 1;
    const sel = el('phCatParent');
    sel.innerHTML = '<option value="">— kategoria główna —</option>' + categories.map(c => {
      const blocked = editingCat && (c.id === editingCat.id || catInside(c.id, editingCat.id));
      const tooDeep = c.depth + ownHeight > catMaxDepth;
      return `<option value="${c.id}"${(blocked || tooDeep) ? ' disabled' : ''}>${esc(c.path)}</option>`;
    }).join('');
    sel.value = selected ? String(selected) : '';
    el('phCatParentHint').textContent = `Można zagnieździć do ${catMaxDepth} poziomów (np. gmina → miejscowość → miejsce).`;
  }

  async function loadCategories(){
    const d = await get(`${API}?action=categories`);
    if(!d) return;
    categories = d.items || [];
    catMaxDepth = d.max_depth || 4;
    // w listach wyboru pokazujemy pełną ścieżkę: „Mogilno › Rynek”
    const opts = categories.map(c => `<option value="${c.id}">${esc(c.path)}</option>`).join('');
    el('phCategory').innerHTML = '<option value="">Wszystkie kategorie</option><option value="none">Bez kategorii</option>' + opts;
    el('phUpCategory').innerHTML = '<option value="">— bez kategorii —</option>' + opts;
    el('phEdCategory').innerHTML = '<option value="">— bez kategorii —</option>' + opts;
    el('phCatList').innerHTML = categories.length
      ? categories.map(c => `
          <div class="cat-row" style="padding-left:${(c.depth - 1) * 18}px">
            <span class="cat-name">${c.depth > 1 ? '<span class="cat-branch">↳</span> ' : ''}${esc(c.name)}</span>
            <span class="cnt">${c.photo_count} zdj.${c.total_count > c.photo_count ? ` <small>(razem ${c.total_count})</small>` : ''}</span>
            ${c.depth < catMaxDepth ? `<button type="button" class="admin-edit-btn" data-cat-sub="${c.id}" title="Dodaj podkategorię">+ podkat.</button>` : ''}
            <button type="button" class="admin-edit-btn" data-cat-edit="${c.id}">Edytuj</button>
            <button type="button" class="admin-del-btn" data-cat="${c.id}">Usuń</button>
          </div>`).join('')
      : '<div class="admin-empty" style="padding:10px 0;">Brak kategorii.</div>';
    fillParentSelect(catEditing !== null ? (categories.find(c => c.id === catEditing) || {}).parent_id : null);
    el('phCatList').querySelectorAll('[data-cat-edit]').forEach(b => {
      b.addEventListener('click', () => {
        const c = categories.find(x => x.id === +b.dataset.catEdit);
        if(c) setCatEdit(c);
      });
    });
    el('phCatList').querySelectorAll('[data-cat-sub]').forEach(b => {
      b.addEventListener('click', () => {
        const parent = categories.find(x => x.id === +b.dataset.catSub);
        if(parent) setCatEdit(null, parent.id);
      });
    });
    el('phCatList').querySelectorAll('[data-cat]').forEach(b => {
      b.addEventListener('click', async () => {
        const c = categories.find(x => x.id === +b.dataset.cat);
        if(c && (c.photo_count > 0 || c.has_children) &&
           !confirm(`Usunąć kategorię „${c.name}”?\nJej zdjęcia i podkategorie przejdą piętro wyżej — nic się nie skasuje.`)) return;
        const d2 = await post({ action:'category_delete', id: +b.dataset.cat });
        if(d2 && d2.ok){
          if(catEditing === +b.dataset.cat) setCatEdit(null);
          toast(d2.moved_photos ? `Usunięto kategorię (zdjęcia przeniesione: ${d2.moved_photos})` : 'Usunięto kategorię');
          await loadCategories(); refresh();
        }
      });
    });
  }

  // formularz kategorii: tryb dodawania (null) albo edycji istniejącej; parentId = wstępnie wybrana nadrzędna
  let catEditing = null;
  function setCatEdit(c, parentId){
    catEditing = c ? c.id : null;
    const parent = c ? c.parent_id : (parentId || null);
    const parentCat = parent ? categories.find(x => x.id === parent) : null;
    el('phCatFormLabel').textContent = c ? 'Edytuj kategorię — nazwa'
      : (parentCat ? `Nowa podkategoria w „${parentCat.name}”` : 'Nowa kategoria');
    el('phCatName').value = c ? c.name : '';
    el('phCatDesc').value = c ? (c.description || '') : '';
    el('phCatAdd').textContent = c ? 'Zapisz' : 'Dodaj';
    el('phCatCancelEdit').style.display = (c || parent) ? '' : 'none';
    fillParentSelect(parent);
    if(c || parent){ el('phCatName').focus(); }
  }

  async function loadList(reset){
    syncStatCards();
    if(busy) return;
    busy = true;
    if(reset){ offset = 0; el('photosList').innerHTML = ''; byId.clear(); }
    const btn = el('phMoreBtn');
    btn.disabled = true;
    try{
      const d = await get(`${API}?${q()}`);
      if(!d) return;
      if(d.attraction_labels) attrCatalog = d.attraction_labels;
      // Limit kategorii z konfiguracji (photo_list dokłada attraction_max_labels).
      if(d.attraction_max_labels) attrMaxLabels = Math.max(1, Math.min(5, parseInt(d.attraction_max_labels, 10) || 2));
      total = d.total || 0;
      el('phCount').textContent = total;
      render(d.items || [], reset);
      offset += (d.items || []).length;
      btn.style.display = offset < total ? 'block' : 'none';
    }catch(_){
      if(reset) el('photosList').innerHTML = '<div class="admin-error">Nie udało się wczytać zdjęć. Sprawdź konsolę przeglądarki.</div>';
    }finally{
      busy = false;
      btn.disabled = false;
    }
  }

  // Odległość osi zdjęcia od środka Mogilna (haversine) — żeby na liście
  // było od razu widać, ile kilometrów od „domu” powstał kadr.
  const MOGNO = { lat: 52.6578, lng: 17.9556 };
  function kmFromMogilno(lat, lng){
    const R = 6371.0088, toRad = d => d * Math.PI / 180;
    const dLat = toRad(lat - MOGNO.lat), dLng = toRad(lng - MOGNO.lng);
    const a = Math.sin(dLat / 2) ** 2
      + Math.cos(toRad(MOGNO.lat)) * Math.cos(toRad(lat)) * Math.sin(dLng / 2) ** 2;
    return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
  }

  function render(items, reset){
    const list = el('photosList');
    if(reset && !items.length){
      list.innerHTML = '<div class="admin-empty">Brak zdjęć. Użyj „+ Dodaj zdjęcie”.</div>';
      return;
    }
    list.insertAdjacentHTML('beforeend', items.map(p => {
      byId.set(p.id, p);
      const meta = [
        p.category_name ? esc(p.category_name) : 'bez kategorii',
        (p.geo && p.geo.miejscowosc) ? esc(p.geo.miejscowosc) : '',
        p.place ? esc(p.place) : '',
        p.camera ? esc(p.camera) : '',
        p.taken_at ? esc(p.taken_at) : '',
        (p.width && p.height) ? `${p.width}×${p.height}` : ''
      ].filter(Boolean).join(' · ');
      const live = !!p.published && !p.missing;                 // ukryte/zaplanowane nie są widoczne na stronie
      const pageUrl = `index.php?photo=${encodeURIComponent(p.pid)}`;
      const ico = {
        eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        cmt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        heart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.8 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>',
        pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
      };
      let kmMog = (typeof p.kmFromMogilno === 'number' && p.kmFromMogilno !== null)
        ? p.kmFromMogilno
        : null;
    if (kmMog === null && p.lat !== null && p.lng !== null) {
      kmMog = kmFromMogilno(p.lat, p.lng);
    }
    kmMog = (kmMog === null || !isFinite(kmMog)) ? null : kmMog;
      let locHtml;
      if(p.lat !== null && p.lng !== null){
        locHtml = `<a class="admin-chip loc-yes" href="https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lng}#map=15/${p.lat}/${p.lng}" target="_blank" rel="noopener" title="${p.lat}, ${p.lng} — otwórz na mapie">${ico.pin} lokalizacja</a>`;
      } else if(p.has_location){
        locHtml = `<span class="admin-chip loc-yes" style="color:var(--teal)" title="Zapisany link do mapy">${ico.pin} lokalizacja</span>`;
      } else {
        locHtml = `<span class="admin-chip loc-no" title="Nie dodano lokalizacji">${ico.pin} brak lokalizacji</span>`;
      }
      const EDIT_TXT = { original:'oryginał', suspect:'możliwa edycja', edited:'edytowane', unknown:'edycja: nieznane' };
      const editChip = EDIT_TXT[p.edit_status]
        ? `<span class="admin-chip edit-chip st-${esc(p.edit_status)}" title="${esc((p.edit_note || '') + (p.edit_manual ? ' (ustawione ręcznie)' : ''))}"><i class="dot"></i> ${EDIT_TXT[p.edit_status]}${p.edit_manual ? ' ✎' : ''}</span>`
        : '';
      const thumbImg = `<img class="admin-thumb admin-thumb-photo" src="${esc(p.thumb)}" alt="" loading="lazy">`;
      const isPending = p.moderation_status === 'pending';
      const isRejected = p.moderation_status === 'rejected';
      const submitterChip = p.submitted_by_username
        ? `<span class="admin-chip" style="color:var(--clay)" title="Zgłoszone przez zwykłego użytkownika">🕓 zgłoszenie: @${esc(p.submitted_by_username)}</span>`
        : '';
      // Znacznik „atrakcja turystyczna” — widoczny na liście zarówno dla zdjęć
      // oczekujących na moderację (zgłoszonych z opcją atrakcji), jak i dla już
      // dodanych. Pokazuje konkretne kategorie (np. ★ atrakcja: Zabytek, Przyroda).
      const attrList = Array.isArray(p.attractions) ? p.attractions : [];
      const attrChip = attrList.length
        ? `<span class="admin-chip attr-chip" title="Zgłoszone jako atrakcja turystyczna (${esc(attrList.map(a => a.name || a.label).join(', '))})">★ atrakcja${attrList.length === 1 ? '' : 'e'}: ${esc(attrList.map(a => a.name || a.label).join(', '))}</span>`
        : '';
      // Rozpoznanie gminy ze współrzędnych (api.adresy.app) + propozycja kategorii
      // z EXIF (gmina → miejscowość). Propozycja nie zatwierdza sama zdjęcia —
      // decyzja zapada w oknie „Sprawdź i zatwierdź”.
      let geoCallout = '';
      const needLabel = p.geo && p.geo.need
        ? ((p.geo.need.parent_name ? p.geo.need.parent_name + ' → ' : '') + p.geo.need.child_name)
        : '';
      if(needLabel){
        geoCallout = `<div class="admin-geo-callout geo-proposed">Proponowana kategoria: <b>${esc(needLabel)}</b> — sprawdź i zatwierdź z właściwą kategorią.</div>`;
      } else if(p.gmina_detected && p.geo_status === 'proposed'){
        geoCallout = `<div class="admin-geo-callout geo-proposed">🆕 Wykryto nową gminę: <b>${esc(p.gmina_detected)}</b> — kategoria nie istnieje. Przy zatwierdzaniu zdecydujesz, czy ją dodać.</div>`;
      } else if(p.gmina_detected && p.geo_status === 'out_of_range'){
        geoCallout = `<div class="admin-geo-callout geo-far">📍 Wykryta gmina: <b>${esc(p.gmina_detected)}</b> — poza promieniem 25 km od Mogilna, kategorii nie zaproponowano automatycznie.</div>`;
      } else if(p.geo_status === 'failed'){
        geoCallout = `<div class="admin-geo-callout geo-failed">Nie udało się ustalić gminy ze współrzędnych — wybierz kategorię ręcznie.</div>`;
      }
      const statusLabel = isPending
        ? 'oczekuje na moderację'
        : (isRejected
            ? 'odrzucone'
            : (p.published ? 'opublikowane' : (p.publish_at ? 'zaplanowane: ' + esc(fmtSchedule(p.publish_at)) : 'ukryte')));
      const actionsHtml = isPending
        ? `<button type="button" class="admin-edit-btn" data-edit="${p.id}">Edytuj</button>
           <button type="button" class="admin-pub-btn" data-approve="${p.id}" title="Zatwierdź od razu, bez wchodzenia w zdjęcie">Zatwierdź</button>
           <button type="button" class="admin-check-btn" data-check="${p.id}" title="Otwórz podgląd zdjęcia i danych przed decyzją">Sprawdź</button>
           <button type="button" class="admin-del-btn" data-reject="${p.id}">Odrzuć</button>`
        : (isRejected
            ? `<button type="button" class="admin-edit-btn" data-edit="${p.id}">Edytuj</button>
               <button type="button" class="admin-pub-btn" data-approve="${p.id}">Zatwierdź ponownie</button>
               <button type="button" class="admin-del-btn" data-del="${p.id}">Usuń</button>`
            : `<button type="button" class="admin-edit-btn" data-edit="${p.id}">Edytuj</button>
               <button type="button" class="admin-pub-btn" data-pub="${p.id}">${p.published ? 'Ukryj' : (p.publish_at ? 'Publikuj teraz' : 'Publikuj')}</button>
               <button type="button" class="admin-del-btn" data-del="${p.id}">Usuń</button>`);
      return `
      <div class="admin-item${p.published ? '' : ' unpublished'}" data-pid="${p.id}">
        ${phSelected.has(p.id)
          ? `<input type="checkbox" class="ph-select" data-id="${p.id}" checked aria-label="Odznacz">`
          : `<input type="checkbox" class="ph-select" data-id="${p.id}" aria-label="Zaznacz">`}
        ${p.missing
          ? '<div class="admin-thumb admin-thumb-photo admin-thumb-empty"></div>'
          : (live
              ? `<a class="admin-thumb-link" href="${pageUrl}" target="_blank" rel="noopener" title="Otwórz na stronie">${thumbImg}</a>`
              : thumbImg.replace('<img ', `<img data-edit="${p.id}" title="Zdjęcie ukryte — kliknij, aby edytować" `))}
        <div class="admin-item-body">
          <div class="admin-item-head">
            ${live
              ? `<a class="admin-author admin-title-link" href="${pageUrl}" target="_blank" rel="noopener" title="Otwórz na stronie">${esc(p.title || p.filename)}</a>`
              : `<span class="admin-author">${esc(p.title || p.filename)}</span>`}
            <span class="admin-date">${statusLabel}</span>
          </div>
          <div class="admin-photo-name">${esc(p.filename)}${p.missing ? ' — BRAK PLIKU' : ''}</div>
          <div class="admin-text">${meta}${p.description ? '<br>' + esc(p.description) : ''}</div>
          <div class="admin-stats">
            ${submitterChip}
            ${attrChip}
            <span class="admin-chip" title="Wyświetlenia w powiększeniu">${ico.eye} ${p.views || 0}</span>
            <span class="admin-chip" title="Komentarze">${ico.cmt} ${p.comments || 0}</span>
            <span class="admin-chip" title="Dodane do ulubionych">${ico.heart} ${p.favorites || 0}</span>
            ${kmMog !== null
              ? `<span class="admin-chip kmt-${kmMog <= 25 ? 'ok' : 'far'}" title="Punkt zdjęcia względem środka Mogilna" data-km="${kmMog}">${ico.pin} ${kmMog} km od Mogilna</span>`
              : ''}
            ${locHtml}
            ${editChip}
          </div>
          ${geoCallout}
        </div>
        <div class="admin-item-actions">
          ${actionsHtml}
        </div>
      </div>`;
    }).join(''));

    list.querySelectorAll('[data-edit]').forEach(b => {
      if(b._b) return; b._b = true;
      b.addEventListener('click', () => openEdit(+b.dataset.edit));
    });
    list.querySelectorAll('[data-pub]').forEach(b => {
      if(b._b) return; b._b = true;
      b.addEventListener('click', async () => {
        const p = byId.get(+b.dataset.pub);
        const d = await post({ action:'photo_publish', id:p.id, published: p.published ? 0 : 1 });
        if(d && d.ok){ toast(d.published ? 'Opublikowano' : 'Ukryto'); refresh(); }
        else if(d){ toast(d.message || 'Nie udało się zmienić widoczności.'); }
      });
    });
    list.querySelectorAll('[data-del]').forEach(b => {
      if(b._b) return; b._b = true;
      b.addEventListener('click', async () => {
        const p = byId.get(+b.dataset.del);
        if(!confirm(`Usunąć zdjęcie „${p.title || p.filename}” razem z plikiem?`)) return;
        const d = await post({ action:'photo_delete', id:p.id });
        if(d && d.ok){ toast('Usunięto zdjęcie'); refresh(); }
      });
    });
    list.querySelectorAll('[data-approve]').forEach(b => {
      if(b._b) return; b._b = true;
      b.addEventListener('click', async () => {
        const p = byId.get(+b.dataset.approve);
        // Szybka ścieżka: zatwierdzenie bez okna i bez potwierdzenia —
        // gdy admin chce najpierw obejrzeć, używa osobnego „Sprawdź”.
        const d = await post({ action:'photo_moderate', id:p.id, decision:'approve' });
        if(d && d.ok){ toast(d.created_category_id ? 'Zatwierdzono zdjęcie i dodano proponowaną kategorię' : 'Zatwierdzono zdjęcie'); refresh(); }
        else if(d){ toast(d.message || 'Nie udało się zatwierdzić'); }
      });
    });
    list.querySelectorAll('[data-check]').forEach(b => {
      if(b._b) return; b._b = true;
      b.addEventListener('click', () => {
        const p = byId.get(+b.dataset.check);
        if(p) openReview(p);
      });
    });
    list.querySelectorAll('[data-reject]').forEach(b => {
      if(b._b) return; b._b = true;
      b.addEventListener('click', () => {
        const p = byId.get(+b.dataset.reject);
        if(!p) return;
        openPhotoRejectForm(p, b);
      });
    });
    list.querySelectorAll('.ph-select').forEach(box => {
      if(box._b) return; box._b = true;
      box.addEventListener('change', () => {
        const id = +box.dataset.id;
        if(box.checked){ phSelected.add(id); } else { phSelected.delete(id); }
        updatePhBulkBar();
        updatePhSelectVisibleState();
      });
    });
  }

  let gminaPending = null;
  // etykiety atrakcji aktywne w oknie moderacji (klucze); inicjowane z p.attractions
  let revAttr = [];
  const EDIT_TXT_REV = { original:'oryginał', suspect:'możliwa edycja', edited:'edytowane', unknown:'edycja: nieznana' };

  function revRow(k, v){
    return `<div class="k">${esc(k)}</div><div class="v">${esc(v)}</div>`;
  }
  function revSection(t){
    return `<div class="sec">${esc(t)}</div>`;
  }

  // Chipy etykiet w oknie „Sprawdź i zatwierdź”. Chip = nazwa + przycisk ×,
  // który usuwa etykietę; lista rozwijana pozwala dodać kolejną. Zbiór trafia
  // do photo_moderate jako pole `labels` razem z decyzją.
  function revAttrName(key){ return attrCatalog[key] || key; }
  function renderRevAttr(){
    const box = el('revAttrChips');
    if(!box) return;
    // Twardy limit jak w edycji — moderator też wybiera maks. attrMaxLabels.
    revAttr = Array.isArray(revAttr) ? revAttr.slice(0, attrMaxLabels) : [];
    box.innerHTML = revAttr.length
      ? revAttr.map(k => `<span class="chip">${esc(revAttrName(k))}<button type="button" class="chip-x" data-attr-remove="${esc(k)}" title="Usuń etykietę" aria-label="Usuń etykietę">&times;</button></span>`).join('') + `<span class="rev-attr-empty" style="margin-left:6px">${revAttr.length} / ${attrMaxLabels}</span>`
      : '<span class="rev-attr-empty">brak — zdjęcie nie jest oznaczone jako atrakcja</span>';
    box.querySelectorAll('[data-attr-remove]').forEach(b => {
      b.addEventListener('click', () => {
        revAttr = revAttr.filter(k => k !== b.dataset.attrRemove);
        renderRevAttr();
      });
    });

    const sel = el('revAttrSelect');
    if(!sel) return;
    // Gdy limit osiągnięty, nie proponujemy kolejnych etykiet.
    const capped = revAttr.length >= attrMaxLabels;
    const remaining = capped ? [] : Object.keys(attrCatalog).filter(k => !revAttr.includes(k));
    sel.innerHTML = capped
      ? `<option value="">— limit ${attrMaxLabels} osiągnięty —</option>`
      : (remaining.length
        ? remaining.map(k => `<option value="${esc(k)}">${esc(attrCatalog[k])}</option>`).join('')
        : '<option value="">— wszystkie dodane —</option>');
    sel.disabled = remaining.length === 0;
    el('revAttrAdd').disabled = remaining.length === 0;
  }
  el('revAttrAdd').addEventListener('click', () => {
    const sel = el('revAttrSelect');
    const k = sel && sel.value;
    if(k && !revAttr.includes(k) && revAttr.length < attrMaxLabels){ revAttr.push(k); renderRevAttr(); }
  });

  // Checkboxy etykiet w oknie edycji zdjęcia (phEditBackdrop). Zaznaczone
  // wartości trafiają do photo_update jako `labels`, notatka jako `attraction_note`.
  // Limit z katalogu (attrMaxLabels, domyślnie 2) — nadmiarowe blokowane jak w panelu.
  let attrMaxLabels = 2;
  function renderEdAttr(selected){
    const box = el('phEdAttrList');
    if(!box) return;
    selected = Array.isArray(selected) ? selected : [];
    selected = selected.slice(0, attrMaxLabels);
    const keys = Object.keys(attrCatalog);
    if(!keys.length){
      box.innerHTML = '<span class="rev-attr-empty">brak zdefiniowanych etykiet</span>';
      return;
    }
    box.innerHTML = keys.map(k => {
      const on = selected.includes(k);
      const locked = !on && selected.length >= attrMaxLabels;
      return `<label class="ed-attr-item"${locked ? ' style="opacity:.45"' : ''}><input type="checkbox" value="${esc(k)}"${on ? ' checked' : ''}${locked ? ' disabled' : ''}> ${esc(attrCatalog[k])}</label>`;
    }).join('') + `<div class="hint" style="grid-column:1/-1">Wybrano ${selected.length} / ${attrMaxLabels} — maks. ${attrMaxLabels} na zdjęcie.</div>`;
    box.querySelectorAll('input[type=checkbox]').forEach(cb => {
      cb.addEventListener('change', () => {
        const cur = [...box.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
        renderEdAttr(cur);
      }, { once:true });
    });
  }

  // czytelne jednostki dla parametrów zapisanych w photos.exif_json jako liczby
  function exifValue(key, raw){
    if(key === 'focal') return raw + ' mm';
    if(key === 'aperture') return 'f/' + raw;
    if(key === 'exposure'){
      const v = parseFloat(raw);
      return (isFinite(v) && v > 0 && v < 1) ? ('1/' + Math.round(1 / v) + ' s') : (raw + ' s');
    }
    return raw;
  }

  async function openReview(p){
    gminaPending = p;
    if(!categories.length){ await loadCategories(); }

    const prev = el('revPreview');
    if(p.missing){
      prev.hidden = true;
    } else {
      el('revImg').src = p.thumb;
      prev.hidden = false;
    }

    // proponowana kategoria z EXIF — to, o co prosi zgłaszający
    const need = p.geo && p.geo.need;
    const needLabel = need ? ((need.parent_name ? need.parent_name + ' → ' : '') + need.child_name) : '';
    const callout = el('revGeo');
    if(needLabel){
      callout.className = 'admin-geo-callout geo-proposed';
      callout.innerHTML = `Proponowana kategoria: <b>${esc(needLabel)}</b> — sprawdź ją przed zatwierdzeniem, możesz wskazać inną.`;
    } else if(p.gmina_detected && p.geo_status === 'proposed'){
      callout.className = 'admin-geo-callout geo-proposed';
      callout.innerHTML = `Wykryto nową gminę: <b>${esc(p.gmina_detected)}</b> — kategoria nie istnieje. Możesz ją dodać przy zatwierdzeniu.`;
    } else if(p.gmina_detected && p.geo_status === 'out_of_range'){
      callout.className = 'admin-geo-callout geo-far';
      callout.innerHTML = `Wykryta gmina: <b>${esc(p.gmina_detected)}</b> — poza promieniem 25 km od Mogilna, kategorii nie zaproponowano automatycznie.`;
    } else if(p.gmina_detected){
      callout.className = 'admin-geo-callout geo-ok';
      const placeTxt = (p.geo && p.geo.miejscowosc) ? ' → ' + esc(p.geo.miejscowosc) : '';
      const catTxt = p.category_name ? ' Przypisana kategoria: <b>' + esc(p.category_name) + '</b>.' : '';
      callout.innerHTML = `Rozpoznana lokalizacja: <b>${esc(p.gmina_detected)}</b>${placeTxt}.${catTxt}`;
    } else if(p.geo_status === 'failed'){
      callout.className = 'admin-geo-callout geo-failed';
      callout.textContent = 'Nie udało się ustalić gminy ze współrzędnych — wskaż kategorię ręcznie.';
    } else {
      callout.className = 'admin-geo-callout';
      callout.textContent = 'Brak danych o lokalizacji — wskaż kategorię ręcznie.';
    }

    let html = '';
    html += revSection('Odczytane informacje z pliku (EXIF)');
    const ex = p.exif || {};
    const exKeys = Object.keys(ex);
    if(exKeys.length){
      exKeys.forEach(k => { html += revRow(ex[k].label, exifValue(k, ex[k].value)); });
    } else {
      html += revRow('EXIF', 'brak — plik nie zawiera danych aparatu');
    }
    if(p.width && p.height) html += revRow('Wymiary pliku', p.width + '×' + p.height);
    html += revRow('Współrzędne GPS',
      (p.lat !== null && p.lng !== null) ? (p.lat + ', ' + p.lng) : 'brak');
    html += revRow('Ocena modyfikacji',
      (EDIT_TXT_REV[p.edit_status] || p.edit_status || 'nie sprawdzono') + (p.edit_manual ? ' (ustawiona ręcznie)' : ''));
    if(p.edit_note) html += revRow('Uwagi do oceny', p.edit_note);

    html += revSection('Miejsce z lokalizacji');
    if(p.gmina_detected) html += revRow('Gmina', p.gmina_detected);
    if(p.geo && p.geo.miejscowosc) html += revRow('Miejscowość', p.geo.miejscowosc);
    if(p.geo && (p.geo.ulica || p.geo.nr)) html += revRow('Ulica i numer', [p.geo.ulica, p.geo.nr].filter(Boolean).join(' '));
    if(p.geo && p.geo.kod_pocztowy) html += revRow('Kod pocztowy', p.geo.kod_pocztowy);
    if(p.geo && p.geo.powiat) html += revRow('Powiat', p.geo.powiat);
    if(p.geo && p.geo.wojewodztwo) html += revRow('Województwo', p.geo.wojewodztwo);
    if(!p.gmina_detected) html += revRow('Gmina', 'nie udało się ustalić');
    if(needLabel) html += revRow('Proponowana kategoria', needLabel);
    if(p.place) html += revRow('Miejsce (opis zgłaszającego)', p.place);

    html += revSection('Zgłoszenie');
    html += revRow('Tytuł', p.title || '—');
    html += revRow('Plik', p.filename);
    if(p.submitted_by_username) html += revRow('Zgłosił', '@' + p.submitted_by_username);
    if(p.request){
      html += revRow('Wniosek o kategorię', '#' + p.request.id + ' (' + p.request.child_name + ')');
    }
    html += revRow('Aktualna kategoria', p.category_name || 'brak');
    el('revExif').innerHTML = html;

    const opts = categories.map(c => `<option value="${c.id}">${esc(c.path)}</option>`).join('');
    el('gminaCatSelect').innerHTML = '<option value="">— zatwierdź bez kategorii —</option>' + opts;
    if(p.category_id) el('gminaCatSelect').value = String(p.category_id);
    el('revCatHint').textContent = needLabel
      ? '„Dodaj proponowaną kategorię” utworzy „' + needLabel + '” i przypisze do niej to zdjęcie. Możesz też wskazać dowolną istniejącą kategorię albo zatwierdzić bez kategorii.'
      : 'Wskaż istniejącą kategorię albo zatwierdź zdjęcie bez kategorii.';
    el('gminaCreate').disabled = !needLabel;

    // etykiety atrakcji zgłoszone przez użytkownika — moderator może je zmienić
    // przed zatwierdzeniem; nowy zbiór leci do API razem z decyzją
    revAttr = Array.isArray(p.attractions) ? p.attractions.map(a => a.label) : [];
    renderRevAttr();

    el('gminaBackdrop').classList.add('open');
  }
  el('gminaCancel').addEventListener('click', () => { el('gminaBackdrop').classList.remove('open'); gminaPending = null; });
  el('gminaCreate').addEventListener('click', async () => {
    const p = gminaPending;
    if(!p) return;
    const d = await post({ action:'photo_moderate', id:p.id, decision:'approve', create_gmina:1, labels: revAttr });
    if(d && d.ok){
      el('gminaBackdrop').classList.remove('open');
      gminaPending = null;
      toast('Dodano proponowaną kategorię i zatwierdzono zdjęcie');
      await loadCategories();
      refresh();
    } else if(d){ toast(d.message || 'Nie udało się zatwierdzić'); }
  });
  el('gminaAssign').addEventListener('click', async () => {
    const p = gminaPending;
    if(!p) return;
    const catId = el('gminaCatSelect').value;
    const payload = { action:'photo_moderate', id:p.id, decision:'approve', create_gmina:0, labels: revAttr };
    if(catId){ payload.category_id = catId; }
    const d = await post(payload);
    if(d && d.ok){
      el('gminaBackdrop').classList.remove('open');
      gminaPending = null;
      toast('Zatwierdzono zdjęcie');
      refresh();
    } else if(d){ toast(d.message || 'Nie udało się zatwierdzić'); }
  });
  // Edycja przed publikacją: ze okna „Sprawdź” przechodzimy do formularza edycji,
  // żeby poprawić tytuł, kategorię, opis czy lokalizację jeszcze przed zatwierdzeniem.
  el('gminaEdit').addEventListener('click', () => {
    const p = gminaPending;
    if(!p) return;
    el('gminaBackdrop').classList.remove('open');
    openEdit(p.id);
  });

  function openEdit(id){
    const p = byId.get(id);
    if(!p) return;
    editing = id;
    el('phEditTitle').textContent = 'Edytuj zdjęcie';
    el('phEditPreview').style.display = 'block';
    el('phEditPreview').innerHTML = p.missing
      ? 'Brak pliku na serwerze: ' + esc(p.filename)
      : `<img src="${esc(p.thumb)}" alt="" style="max-width:100%;border-radius:6px;">`;
    el('phEdTitle').value = p.title || '';
    el('phEdDesc').value = p.description || '';
    el('phEdCategory').value = p.category_id === null ? '' : String(p.category_id);
    el('phEdPlace').value = p.place || '';

    // Pole przyjmuje link Google Maps, więc pokazujemy link zbudowany
    // z zapisanych współrzędnych. Ponowne zapisanie bez zmian odczyta te same
    // współrzędne, a nie wyczyści lokalizację.
    el('phEdCoordinates').value = makeGoogleLinkFromCoords(p.lat, p.lng);
    el('phEdDate').value = p.taken_at || '';
  
    el('phEdPublished').checked = !!p.published;
    el('phEdPublishAt').value = toLocalInput(p.publish_at);
    syncEdSchedule();

    // status modyfikacji: '' = liczony automatycznie z EXIF/XMP, inaczej ustawiony ręcznie
    editStatusInitial = p.edit_manual ? (p.edit_status || '') : '';
    editNoteInitial = p.edit_manual ? (p.edit_note || '') : '';
    el('phEdEditStatus').value = editStatusInitial;
    el('phEdEditNote').value = editNoteInitial === 'Status ustawiony ręcznie przez administratora.' ? '' : editNoteInitial;
    el('phEdAutoNote').textContent = p.edit_note
      ? 'Ocena programu: ' + p.edit_note + (p.camera ? ' Aparat: ' + p.camera + '.' : '')
      : 'Brak automatycznej oceny (zdjęcie jeszcze nie zostało przeanalizowane).';
    syncEditNoteBox();

    // etykiety atrakcji — zaznacz aktualne, wypełnij notatkę (wspólna dla zdjęcia)
    const curAttr = Array.isArray(p.attractions) ? p.attractions : [];
    renderEdAttr(curAttr.map(a => a.label));
    el('phEdAttrNote').value = curAttr.length ? (curAttr[0].note || '') : '';

    el('phEditBackdrop').classList.add('open');
  }
  let editStatusInitial = '', editNoteInitial = '';
  function syncEditNoteBox(){
    el('phEdEditNoteBox').style.display = el('phEdEditStatus').value ? 'block' : 'none';
  }
  el('phEdEditStatus').addEventListener('change', syncEditNoteBox);

  el('phEdCancel').addEventListener('click', () => el('phEditBackdrop').classList.remove('open'));
  el('phEdSave').addEventListener('click', async () => {
    const btn = el('phEdSave');
    btn.disabled = true;
    try{
      const mapsLink = normalizeMapsLink(el('phEdCoordinates').value);
      if(mapsLink === null){
        toast('Link do miejsca musi zaczynać się od https://');
        return;
      }

      const payload = {
        action:'photo_update',
        id: editing,
        title: el('phEdTitle').value,
        description: el('phEdDesc').value,
        category_id: el('phEdCategory').value,
        place: el('phEdPlace').value,
        taken_at: el('phEdDate').value,
        coordinates: mapsLink,
        published: el('phEdPublished').checked ? 1 : 0,
        publish_at: el('phEdPublished').checked ? '' : el('phEdPublishAt').value,
        // jak przy uploadzie: pozwól backendowi założyć brakującą kategorię
        // (gminę / miejscowość) na podstawie rozpoznanych współrzędnych
        auto_categories: 1,
        // etykiety atrakcji: zaznaczone checkboxy + wspólna notatka
        labels: [...el('phEdAttrList').querySelectorAll('input[type=checkbox]:checked')].map(c => c.value),
        attraction_note: el('phEdAttrNote').value.trim()
      };
      // status wysyłamy tylko po zmianie — inaczej zapis zdjęcia nie ruszałby ręcznego ustawienia
      const newStatus = el('phEdEditStatus').value;
      const newNote = el('phEdEditNote').value.trim();
      if(newStatus !== editStatusInitial || (newStatus && newNote !== editNoteInitial)){
        payload.edit_status = newStatus;
        payload.edit_note = newNote;
      }
      const d = await post(payload);
      if(d && d.ok){
        // Świeże etykiety z odpowiedzi — podgląd zaktualizuje się od razu,
        // bez czekania na ponowne wczytanie listy.
        const p = byId.get(editing);
        if(p && Array.isArray(d.labels)) p.attractions = d.labels;
        el('phEditBackdrop').classList.remove('open');
        toast('Zapisano');
        refresh();
      } else if(d){
        toast('Nie zapisano: ' + (d.message || d.error || 'błąd'));
      }
    } finally { btn.disabled = false; }
  });

  let envChecked = false;
  let adminPreviewUrl = null;
  let adminUploadRun = 0;
  // Plik do wysyłki: po konwersji na JPG w przeglądarce (filmy, animacje, PNG),
  // inaczej oryginał. Input plikowy zostaje nietknięty — phUpConfirm bierze
  // tę zmienną, żeby na serwer nigdy nie pojechał surowy film.
  let adminUploadFile = null;
  const adminConvertExt = ['heic', 'heif', 'avif', 'tif', 'tiff', 'bmp'];
  // Formaty filmowe dekodowane przez przeglądarkę (klatka na JPG); reszta
  // nieudanych dekodowań (np. HEVC w Chrome) łapie się komunikatem.
  const adminVideoExt = ['mov', 'mp4', 'm4v', 'webm', '3gp', 'mts', 'm2ts', 'avi', 'mkv'];
  const phUpDrop = el('phUpDrop');

  function adminUploadBytes(bytes){
    const n = Number(bytes) || 0;
    if(n < 1048576) return (n / 1024).toFixed(1) + ' kB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  // Czy plik wymaga zamiany na JPG w przeglądarce? JPG i formaty przetwarzane
  // przez serwer (HEIC/AVIF/TIF/BMP) idą bez zmian — tak jak w panelu użytkownika.
  function adminNeedsJpegConvert(file){
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    return ext !== 'jpg' && ext !== 'jpeg' && adminConvertExt.indexOf(ext) === -1;
  }

  // Zamiana pliku na JPG w przeglądarce: film → jedna klatka, animacja →
  // statyczny obraz, PNG/WebP → JPG na białym tle. Kod jak w panelu użytkownika
  // (tam fileToJpeg) — te dwa pliki i tak żyją osobno, więc helper jest zdublowany.
  async function adminFileToJpeg(file){
    if(!adminNeedsJpegConvert(file)) return file;

    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const isVideo = (file.type || '').toLowerCase().indexOf('video/') === 0
      || adminVideoExt.indexOf(ext) !== -1;
    const url = URL.createObjectURL(file);
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    try{
      if(isVideo){
        const video = document.createElement('video');
        video.preload = 'auto';
        video.muted = true;
        video.playsInline = true;
        video.src = url;
        await new Promise((resolve, reject) => {
          const timer = setTimeout(() => reject(new Error('video_timeout')), 30000);
          video.onloadeddata = () => { clearTimeout(timer); resolve(); };
          video.onerror = () => { clearTimeout(timer); reject(new Error('video_decode')); };
        });
        // Klatka zerowa bywa czarna — przesuwamy minimalnie do przodu; gdy seek
        // nie dojdzie, rysujemy klatkę, którą mamy.
        const seek = Math.min(0.1,
          (Number.isFinite(video.duration) && video.duration > 0.2) ? video.duration / 2 : 0.1);
        if(seek > 0){
          await new Promise((resolve) => {
            const timer = setTimeout(resolve, 5000);
            video.onseeked = () => { clearTimeout(timer); resolve(); };
            video.onerror = () => { clearTimeout(timer); resolve(); };
            try{ video.currentTime = seek; }catch(_){ clearTimeout(timer); resolve(); }
          });
        }
        const w = video.videoWidth, h = video.videoHeight;
        if(!w || !h) throw new Error('video_dims');
        canvas.width = w;
        canvas.height = h;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(video, 0, 0, w, h);
        video.removeAttribute('src');
        video.load();
      }else{
        const img = new Image();
        await new Promise((resolve, reject) => {
          img.onload = () => resolve();
          img.onerror = () => reject(new Error('image_decode'));
          img.src = url;
        });
        const w = img.naturalWidth, h = img.naturalHeight;
        if(!w || !h) throw new Error('image_dims');
        canvas.width = w;
        canvas.height = h;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0);
      }
      const blob = await new Promise((resolve, reject) => {
        canvas.toBlob((b) => b ? resolve(b) : reject(new Error('to_blob')),
          'image/jpeg', 0.92);
      });
      const base = file.name.replace(/\.[^./\\]+$/, '') || 'zdjecie';
      return new File([blob], base + '.jpg', { type: 'image/jpeg' });
    }finally{
      URL.revokeObjectURL(url);
    }
  }

  async function showAdminUploadPreview(file){
    const box = el('phUpPreview');
    const img = el('phUpThumb');
    if(adminPreviewUrl){ URL.revokeObjectURL(adminPreviewUrl); adminPreviewUrl = null; }
    if(!file){ box.style.display = 'none'; img.removeAttribute('src'); return; }

    el('phUpName').textContent = file.name;
    el('phUpMeta').textContent = adminUploadBytes(file.size);
    let source = file;
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if(adminConvertExt.indexOf(ext) !== -1){
      const fd = new FormData();
      fd.append('file', file, file.name);
      fd.append('csrf', csrf);
      try{
        const res = await fetch('submit-photo-api.php?action=preview', { method:'POST', credentials:'include', body:fd });
        if(res.ok) source = await res.blob();
      }catch(_){ /* oryginalny HEIC zostanie opisany nazwą, jeśli podgląd się nie uda */ }
    }
    adminPreviewUrl = URL.createObjectURL(source);
    img.src = adminPreviewUrl;
    box.style.display = 'flex';
    return source;
  }

  function readAdminExif(file){
    return new Promise(resolve => {
      if(!file){ resolve(null); return; }
      const fr = new FileReader();
      fr.onerror = () => resolve(null);
      fr.onload = () => {
        try{
          const dv = new DataView(fr.result);
          if(dv.byteLength < 4 || dv.getUint16(0, false) !== 0xFFD8){ resolve(null); return; }
          let off = 2;
          while(off + 4 <= dv.byteLength){
            if(dv.getUint8(off) !== 0xFF){ off++; continue; }
            const marker = dv.getUint8(off + 1);
            if(marker === 0xDA || marker === 0xD9) break;
            if(marker === 0x00 || (marker >= 0xD0 && marker <= 0xD7)){ off += 2; continue; }
            const size = dv.getUint16(off + 2, false), seg = off + 4;
            if(size < 2) break;
            if(marker === 0xE1 && dv.getUint32(seg, false) === 0x45786966){
              resolve(parseAdminExifTiff(dv, seg + 6)); return;
            }
            off = seg + size - 2;
          }
          resolve(null);
        }catch(_){ resolve(null); }
      };
      fr.readAsArrayBuffer(file);
    });
  }

  function parseAdminExifTiff(dv, start){
    const le = dv.getUint16(start, false) === 0x4949;
    const u16 = o => dv.getUint16(o, le), u32 = o => dv.getUint32(o, le);
    if(u16(start + 2) !== 0x002A) return null;
    const sizeOf = t => ({1:1,2:1,3:2,4:4,5:8,7:1}[t] || 1);
    const entries = at => { const n = u16(at), out = {}; for(let i=0;i<n;i++){ const e=at+2+i*12; out[u16(e)]={type:u16(e+2),count:u32(e+4),value:e+8}; } return out; };
    const dataAt = e => e.count * sizeOf(e.type) > 4 ? start + u32(e.value) : e.value;
    const text = e => { let s='', p=dataAt(e); for(let i=0;i<e.count;i++){ const c=dv.getUint8(p+i); if(!c) break; s+=String.fromCharCode(c); } return s; };
    const dms = e => { if(e.count < 3) return null; const p=dataAt(e), n=i=>u32(p+i*8), d=i=>u32(p+i*8+4); return (d(0)?n(0)/d(0):0)+(d(1)?n(1)/d(1):0)/60+(d(2)?n(2)/d(2):0)/3600; };
    const root = entries(start + u32(start + 4));
    let takenAt = '';
    const dateTag = root[0x9003] || root[0x0132];
    if(dateTag && dateTag.type === 2){ const m=/^(\d{4}):(\d{2}):(\d{2})/.exec(text(dateTag)); if(m) takenAt=m[1]+'-'+m[2]+'-'+m[3]; }
    const gps = root[0x8825];
    if(!gps) return takenAt ? {takenAt} : null;
    const g = entries(start + u32(gps.value));
    if(!g[2] || !g[4]) return takenAt ? {takenAt} : null;
    let lat=dms(g[2]), lng=dms(g[4]);
    if(g[1] && text(g[1]) === 'S') lat=-lat;
    if(g[3] && text(g[3]) === 'W') lng=-lng;
    if(!Number.isFinite(lat) || !Number.isFinite(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) return takenAt ? {takenAt} : null;
    return {lat, lng, takenAt};
  }

  function showAdminUploadStep(step){
    ['pick','scan','form'].forEach(name => el('phStep' + name[0].toUpperCase() + name.slice(1)).classList.toggle('on', name === step));
    el('phAdminOptions').classList.toggle('on', step === 'form');
    el('phUpSub').textContent = step === 'pick'
      ? 'Trzy kroki: wybierz plik, poczekaj na dane, uzupełnij szczegóły.'
      : (step === 'scan' ? 'Zaglądamy do pliku — za chwilę pokażemy, co udało się z niego wyczytać.' : 'Uzupełnij szczegóły i wybierz sposób publikacji.');
  }

  async function beginAdminUpload(file){
    if(!file) return;
    const run = ++adminUploadRun;

    // Filmy i grafiki spoza JPG/HEIC zamieniamy na JPG, zanim ktokolwiek zobaczy
    // podgląd — dalej cała ścieżka (podgląd, EXIF, wysyłka) działa jak dawniej.
    if(adminNeedsJpegConvert(file)){
      el('phUpPicked').textContent = file.name + ' → przetwarzamy na JPG…';
      el('phUpScanFile').textContent = file.name + ' · przetwarzanie na JPG…';
      showAdminUploadStep('scan');
      try{
        const converted = await adminFileToJpeg(file);
        if(run !== adminUploadRun) return;
        file = converted;
        el('phUpPicked').textContent = converted.name + ' (' + adminUploadBytes(converted.size) + ')';
      }catch(_){
        if(run !== adminUploadRun) return;
        adminUploadFile = null;
        el('phUpFiles').value = '';
        el('phUpPicked').textContent = '';
        showAdminUploadStep('pick');
        toast('Nie udało się przetworzyć pliku na JPG w tej przeglądarce — film z iPhone’a (HEVC) spróbuj otworzyć w Safari albo zapisz plik wcześniej jako JPG.');
        return;
      }
    }
    adminUploadFile = file;

    el('phUpScanFile').textContent = file.name + ' · ' + adminUploadBytes(file.size);
    showAdminUploadStep('scan');
    const marks = {};
    el('phUpScanSteps').querySelectorAll('li').forEach(node => { marks[node.dataset.step] = node; });
    const mark = (key, state) => { if(marks[key]) marks[key].className = state || ''; };
    mark('exif', 'on');
    const started = Date.now();
    const source = await showAdminUploadPreview(file);
    const meta = await readAdminExif(source || file);
    if(run !== adminUploadRun) return;
    mark('exif', 'ok');
    const hasGps = !!(meta && Number.isFinite(meta.lat) && Number.isFinite(meta.lng));
    mark('gps', hasGps ? 'ok' : 'skip');
    if(meta && meta.takenAt && !el('phUpDate').value) el('phUpDate').value = meta.takenAt;
    mark('geo', 'on');
    if(hasGps){
      try{
        const res = await fetch('geo-locate-api.php', {
          method:'POST', credentials:'include', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({csrf, lat:meta.lat, lng:meta.lng})
        });
        const geo = await res.json();
        if(geo && geo.ok !== false){
          const place = [geo.miejscowosc, geo.kod_pocztowy].filter(Boolean).join(' ');
          if(place && !el('phUpPlace').value) el('phUpPlace').value = place;
          if(geo.status === 'matched' && geo.category_id && !el('phUpCategory').value) el('phUpCategory').value = String(geo.category_id);
          el('phUpGeoNote').style.display = '';
          el('phUpGeoNote').textContent = geo.gmina ? 'Rozpoznano lokalizację: ' + geo.gmina + (place ? ' — ' + place : '') + '.' : '';
          mark('geo', 'ok');
          mark('cat', geo.category_id ? 'ok' : 'skip');
        } else { mark('geo', 'skip'); mark('cat', 'skip'); }
      }catch(_){ mark('geo', 'skip'); mark('cat', 'skip'); }
    } else {
      mark('geo', 'skip'); mark('cat', 'skip');
    }
    if(run !== adminUploadRun) return;
    const wait = 900 - (Date.now() - started);
    if(wait > 0) await new Promise(resolve => setTimeout(resolve, wait));
    if(run === adminUploadRun) showAdminUploadStep('form');
  }

  el('phUpFiles').addEventListener('change', () => {
    const file = el('phUpFiles').files[0] || null;
    adminUploadFile = null; // po konwersji beginAdminUpload ustawi właściwy plik
    el('phUpPicked').textContent = file ? file.name + ' (' + adminUploadBytes(file.size) + ')' : '';
    beginAdminUpload(file);
  });
  phUpDrop.addEventListener('click', () => el('phUpFiles').click());
  phUpDrop.addEventListener('keydown', (e) => {
    if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); el('phUpFiles').click(); }
  });
  ['dragenter','dragover'].forEach(type => phUpDrop.addEventListener(type, (e) => {
    e.preventDefault(); phUpDrop.classList.add('drag-over');
  }));
  ['dragleave','drop'].forEach(type => phUpDrop.addEventListener(type, (e) => {
    e.preventDefault(); phUpDrop.classList.remove('drag-over');
  }));
  phUpDrop.addEventListener('drop', (e) => {
    const files = e.dataTransfer && e.dataTransfer.files;
    if(!files || !files.length) return;
    el('phUpFiles').files = files;
    el('phUpPicked').textContent = files[0].name + ' (' + adminUploadBytes(files[0].size) + ')';
    beginAdminUpload(files[0]);
  });

  async function checkEnv(){
    if(envChecked) return;
    envChecked = true;
    const d = await get(`${API}?action=env`);
    if(!d) return;
    const notes = [];
    if(!d.can_convert_heic) notes.push('Brak ffmpeg/Imagick — zdjęcia HEIC z iPhone\'a nie zostaną przyjęte.');
    if(notes.length){
      el('phEnvNote').style.display = 'block';
      el('phEnvNote').innerHTML = notes.map(esc).join('<br>') +
        '<br><br>Instrukcja: README.md, sekcja „Zdjęcia z iPhone\'a”.';
    }
  }

  el('phAddBtn').addEventListener('click', () => {
    adminUploadRun++;      // anulujemy ewentualną konwersję w toku
    adminUploadFile = null;
    el('phUpResult').style.display = 'none';
    el('phUpResult').textContent = '';
    el('phUpFiles').value = '';
    el('phUpPicked').textContent = '';
    showAdminUploadPreview(null);
    showAdminUploadStep('pick');
    el('phUpCoordinates').value = '';
    el('phUpDate').value = '';
    el('phUpPublished').checked = true;
    el('phUpPublishAt').value = '';
    el('phUpGeoNote').style.display = 'none';
    el('phUpGeoNote').textContent = '';
    upGeoSig = '';
    syncUpSchedule();

    checkEnv();
    el('phUploadBackdrop').classList.add('open');
  });
  el('phUpCancel').addEventListener('click', () => {
    adminUploadRun++; // konwersja w toku nie zdąży zapisać wyniku po zamknięciu
    el('phUploadBackdrop').classList.remove('open');
  });

  // Podgląd rozpoznania gminy z linku do Map Google (ten sam endpoint co w panelu
  // użytkownika). Nic nie zapisuje — to tylko podpowiedź przed wysłaniem.
  let upGeoTimer = null;
  let upGeoSig = '';
  function updateUpGeoPreview(){
    const note = el('phUpGeoNote');
    if(!note) return;
    const link = el('phUpCoordinates').value.trim();
    if(!/^https?:\/\/\S+$/i.test(link)){ note.style.display = 'none'; note.textContent = ''; upGeoSig = ''; return; }
    if(link === upGeoSig) return;
    upGeoSig = link;
    note.style.display = '';
    note.textContent = 'Sprawdzanie lokalizacji…';
    fetch('geo-locate-api.php', {
      method:'POST', credentials:'include',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ csrf: csrf, coordinates: link })
    }).then(r => r.json()).then(d => {
      if(link !== upGeoSig) return;
      if(!d || d.ok === false){ note.textContent = (d && d.message) || 'Nie udało się rozpoznać lokalizacji.'; return; }
      if(d.status === 'none'){ note.style.display = 'none'; return; }
      const labels = {
        matched: `Rozpoznano gminę: ${d.gmina} — kategoria zostanie przypisana automatycznie, jeśli nie wybierzesz innej.`,
        proposed: `Rozpoznano gminę: ${d.gmina}. Kategorii jeszcze nie ma — przy zatwierdzaniu zdecydujesz o jej dodaniu.`,
        out_of_range: `Rozpoznano gminę: ${d.gmina} — poza okolicą Mogilna, kategorii nie zaproponujemy.`,
        failed: 'Miejsce poza zasięgiem znanych adresów — gminę wybierzesz ręcznie.'
      };
      note.textContent = labels[d.status] || '';
      if(d.status === 'matched' && d.category_id){
        const sel = el('phUpCategory');
        if(!sel.value){ sel.value = String(d.category_id); }
      }
    }).catch(() => { if(link === upGeoSig){ note.textContent = 'Nie udało się rozpoznać lokalizacji (brak połączenia).'; } });
  }
  el('phUpCoordinates').addEventListener('input', () => {
    const note = el('phUpGeoNote');
    if(note){ note.style.display = 'none'; note.textContent = ''; }
    upGeoSig = '';
    clearTimeout(upGeoTimer);
    upGeoTimer = setTimeout(updateUpGeoPreview, 650);
  });

  el('phUpConfirm').addEventListener('click', async () => {
    const files = el('phUpFiles').files;
    if(!files || !files.length){ toast('Wybierz zdjęcie'); return; }
    if(files.length > 1){ toast('Można dodać tylko jedno zdjęcie naraz'); return; }

    const mapsLink = normalizeMapsLink(el('phUpCoordinates').value);
    if(mapsLink === null){
      toast('Link do miejsca musi zaczynać się od https://');
      return;
    }

    const btn = el('phUpConfirm');
    btn.disabled = true;
    btn.classList.add('is-uploading');
    btn.replaceChildren(Object.assign(document.createElement('span'), { className:'admin-upload-loader' }), document.createTextNode('wysyłanie…'));
    try{
      const fd = new FormData();
      fd.append('action', 'photo_upload');
      fd.append('csrf', csrf);
      fd.append('category_id', el('phUpCategory').value);
      fd.append('place', el('phUpPlace').value);
      fd.append('coordinates', mapsLink);
      fd.append('published', el('phUpPublished').checked ? '1' : '0');
      fd.append('taken_at', el('phUpDate').value);
      fd.append('publish_at', el('phUpPublished').checked ? '' : el('phUpPublishAt').value);
      // Bez tej flagi backend nigdy nie założy brakującej kategorii (gminy
      // ani podkategorii miejscowości) mimo rozpoznanych współrzędnych —
      // zdjęcie wylądowałoby bez kategorii. Backend i tak tworzy tylko to,
      // czego brakuje, i tylko gdy admin nie wybrał kategorii jawnie.
      fd.append('auto_categories', '1');
      // Wysyłamy plik po konwersji (JPG); oryginał z inputa (np. .mov) zostaje
      // tylko jako dowód wyboru — na serwer nigdy nie jedzie surowy film.
      const uploadFile = adminUploadFile || files[0];
      fd.append('files[]', uploadFile, uploadFile.name);

      const res = await fetch(API, { method:'POST', credentials:'include', body: fd });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return; }
      const d = await res.json();
      if(d && d.ok){
        const n = (d.added || []).length;
        const skipped = d.skipped || [];
        if(skipped.length){
          // plik odrzucony — zostawiamy okno, żeby było widać dlaczego
          el('phUpResult').style.display = 'block';
          el('phUpResult').innerHTML = `Dodano: ${n}<br>Pominięto:<br>` + skipped.map(esc).join('<br>');
        } else {
          el('phUploadBackdrop').classList.remove('open');
        }
        toast(n === 1 ? 'Dodano zdjęcie' : 'Nie dodano zdjęcia');
        refresh();
      } else {
        toast('Nie udało się wgrać: ' + ((d && (d.message || d.error)) || 'błąd'));
      }
    }catch(_){
      toast('Błąd połączenia');
    }finally{
      btn.disabled = false;
      btn.classList.remove('is-uploading');
      btn.textContent = 'Wyślij';
    }
  });

  el('phCatBtn').addEventListener('click', async () => {
    setCatEdit(null);
    await loadCategories();
    el('phCatBackdrop').classList.add('open');
  });

  el('phCatClose').addEventListener('click', () => el('phCatBackdrop').classList.remove('open'));

  el('phCatAdd').addEventListener('click', async () => {
    const name = el('phCatName').value.trim();
    if(!name){ toast('Podaj nazwę'); return; }
    const wasEditing = catEditing !== null;
    const payload = { action:'category_save', name, description: el('phCatDesc').value, parent_id: el('phCatParent').value };
    if(wasEditing) payload.id = catEditing;
    const d = await post(payload);
    if(d && d.ok){
      setCatEdit(null);
      toast(wasEditing ? 'Zapisano kategorię' : 'Dodano kategorię');
      await loadCategories();
      if(wasEditing) refresh();   // zmieniona nazwa ma się odświeżyć na liście zdjęć
    } else if(d){
      const msgs = {
        duplicate_name: 'W tej kategorii jest już taka nazwa',
        parent_cycle: 'Nie można przenieść kategorii do jej własnej podkategorii',
        too_deep: `Za głębokie zagnieżdżenie (maksymalnie ${d.max_depth || catMaxDepth} poziomy)`
      };
      toast(msgs[d.error] || (wasEditing ? 'Nie udało się zapisać' : 'Nie udało się dodać'));
    }
  });
  el('phCatCancelEdit').addEventListener('click', () => setCatEdit(null));

  el('phScanBtn').addEventListener('click', async () => {
    const d = await post({ action:'photo_scan' });
    if(d && d.ok){
      toast(d.added ? `Zarejestrowano plików: ${d.added}` : 'Brak nowych plików');
      refresh();
    }
  });

  let deb;
  el('phSearch').addEventListener('input', () => {
    clearTimeout(deb);
    deb = setTimeout(() => loadList(true), 300);
  });
  el('phCategory').addEventListener('change', () => loadList(true));
  el('phPublished').addEventListener('change', () => loadList(true));
  el('phUser').addEventListener('change', () => loadList(true));

  // ---------- kafelki statystyk działają jak filtry ----------
  function syncStatCards(){
    const v = el('phPublished').value;
    const plain = !el('phSearch').value.trim() && !el('phCategory').value;
    document.querySelectorAll('[data-stat]').forEach(c => {
      const k = c.dataset.stat;
      c.classList.toggle('active', plain && ((k === 'all' && v === '') || (k === 'pub' && v === '1') || (k === 'hidden' && v === '0') || (k === 'pending' && v === 'pending')));
    });
  }
  function applyStat(k){
    if(k === 'cats'){ el('phCatBtn').click(); return; }
    el('phSearch').value = '';
    el('phCategory').value = '';
    el('phPublished').value = k === 'pub' ? '1' : (k === 'hidden' ? '0' : (k === 'pending' ? 'pending' : ''));
    loadList(true);
  }
  document.querySelectorAll('[data-stat]').forEach(c => {
    c.addEventListener('click', () => applyStat(c.dataset.stat));
    c.addEventListener('keydown', e => {
      if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); applyStat(c.dataset.stat); }
    });
  });
  el('phMoreBtn').addEventListener('click', () => loadList(false));

  // ---------- selekcja masowa: widoczne / wyniki / zatwierdź ----------
  el('phSelectVisible').addEventListener('change', () => {
    const on = el('phSelectVisible').checked;
    document.querySelectorAll('.ph-select').forEach(box => {
      box.checked = on;
      const id = +box.dataset.id;
      if(on){ phSelected.add(id); } else { phSelected.delete(id); }
    });
    updatePhBulkBar();
  });
  el('phSelectResults').addEventListener('click', async () => {
    const p = Object.assign({ action:'photo_list_ids' }, filterParams());
    const d = await get(`${API}?${new URLSearchParams(p).toString()}`);
    if(!d) return;
    if(!d.ids || !d.ids.length){ toast('Brak zdjęć dla tych filtrów'); return; }
    d.ids.forEach(id => phSelected.add(id));
    document.querySelectorAll('.ph-select').forEach(box => { box.checked = phSelected.has(+box.dataset.id); });
    updatePhBulkBar();
    updatePhSelectVisibleState();
    toast(`Zaznaczono ${d.total}`);
  });
  el('phBulkClear').addEventListener('click', () => {
    phSelected.clear();
    document.querySelectorAll('.ph-select').forEach(b => { b.checked = false; });
    el('phSelectVisible').checked = false;
    updatePhBulkBar();
  });
  el('phBulkApprove').addEventListener('click', async () => {
    const ids = [...phSelected];
    if(!ids.length) return;
    const btn = el('phBulkApprove');
    btn.disabled = true;
    try{
      const d = await post({ action:'photo_moderate_bulk', decision:'approve', ids });
      if(d && d.ok){
        toast(d.done > 0
          ? `Zatwierdzono zdjęcia: ${d.done}` + (d.missing ? ` (nie znaleziono: ${d.missing})` : '')
          : 'Nic nie zatwierdzono');
        phSelected.clear();
        el('phSelectVisible').checked = false;
        el('phBulkBar').classList.remove('open');
        refresh();
      } else if(d){ toast(d.message || 'Nie udało się zatwierdzić'); }
    } finally {
      btn.disabled = false;
    }
  });

  [['phUploadBackdrop'], ['phEditBackdrop'], ['phCatBackdrop'], ['gminaBackdrop']].forEach(([id]) => {
    el(id).addEventListener('click', e => { if(e.target === el(id)) el(id).classList.remove('open'); });
  });

  document.addEventListener('keydown', e => {
    if(e.key !== 'Escape') return;
    ['phUploadBackdrop','phEditBackdrop','phCatBackdrop','gminaBackdrop'].forEach(id => el(id).classList.remove('open'));
  });

  function refresh(){
    loadStats();
    loadCategories();
    loadList(true);
  }

  window.zmPhotos = {
    start(token){
      csrf = token || '';
      if(started) return;
      started = true;
      refresh();
      if(!pendingTimer) pendingTimer = setInterval(pollPending, 60000);
    },
    stop(){
      started = false;
      csrf = '';
      el('photosList').innerHTML = '';
      if(pendingTimer){ clearInterval(pendingTimer); pendingTimer = null; }
      phSelected.clear();
      el('phSelectVisible').checked = false;
      el('phBulkBar').classList.remove('open');
      setPendingBadge(0);
    },
    refresh(){
      if(started) refresh();
    }
  };
})();
</script>
<script>
/* ------------------------------------------------------------------
   Tryb jasny / ciemny + notatnik administratora.
   ------------------------------------------------------------------ */
(function(){
  const el=id=>document.getElementById(id),root=document.documentElement;
  const notesOpenKey='zm-admin-notes-open-v1';
  const noteDetailKey='zm-admin-note-detail-v1';
  function applyTheme(t,save){
    if(t==='light')root.setAttribute('data-theme','light');else root.removeAttribute('data-theme');
    const meta=el('metaThemeColor');if(meta)meta.setAttribute('content',t==='light'?'#f5f1e6':'#101c16');
    if(save){try{localStorage.setItem('zmogilna-theme',t);}catch(_){}}
  }
  el('themeBtn').addEventListener('click',()=>applyTheme(root.getAttribute('data-theme')==='light'?'dark':'light',true));
  const PAPI='admin-api.php';
  const esc=str=>String(str??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast=m=>(window.zmToast?window.zmToast(m):null);
  let tasks=[],started=false,detailId=null;
  async function call(action,payload){
    const csrf=window.zmGetCsrf?window.zmGetCsrf():'';
    try{
      const res=payload===undefined&&action==='task_list'?await fetch(`${PAPI}?action=task_list`,{credentials:'include'}):await fetch(PAPI,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action,csrf},payload||{}))});
      if(res.status===401){window.zmSessionLost&&window.zmSessionLost();return null;}
      const d=await res.json();if(d.error){toast('Notatnik: '+(d.message||d.error));return null;}return d;
    }catch(_){toast('Notatnik: błąd połączenia');return null;}
  }
  function adopt(d){if(!d||!Array.isArray(d.items))return;tasks=d.items;render();}
  function dateLabel(ms){
    if(!ms)return 'Brak daty';const d=new Date(Number(ms));
    return Number.isNaN(d.getTime())?'Brak daty':d.toLocaleString('pl-PL',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
  }
  function linkify(value){
    return esc(value).replace(/(https?:\/\/[^\s<]+)/gi,raw=>{
      const url=raw.replace(/[),.!?;:]+$/,'');return `<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>${raw.slice(url.length)}`;
    }).replace(/\n/g,'<br>');
  }
  function paintDetailDone(done){
    const label=el('noteDetailDoneLabel');
    const input=el('noteDetailDone');
    input.checked=!!done;
    label.classList.toggle('is-done',!!done);
    label.querySelector('.done-copy').textContent=done?'Zrobione!':'Oznacz jako zrobione';
    label.querySelector('.done-hint').textContent=done?'Gotowe ✓':'kliknij';
    label.title=done?'Zadanie jest zrobione — kliknij, aby przywrócić':'Oznacz notatkę jako zrobioną';
    input.setAttribute('aria-label',done?'Oznacz jako niezrobione':'Oznacz notatkę jako zrobioną');
  }
  function render(){
    const open=tasks.filter(t=>!t.done),done=tasks.filter(t=>t.done),badge=el('notesBadge');
    badge.textContent=open.length;badge.classList.toggle('show',open.length>0);
    el('notesProgress').textContent=tasks.length?`${open.length} aktywnych · ${done.length} wykonanych`:'';
    el('notesClearDone').style.display=done.length?'':'none';
    const item=t=>`<div class="note-item${t.done?' done':''}" data-id="${t.id}">
      <div class="note-item-controls">
        ${!t.done ? `<button type="button" class="note-doing-btn${t.status==='doing'?' active':''}" data-act="doing" title="${t.status==='doing'?'W trakcie realizacji — kliknij, aby wyłączyć':'Oznacz jako w trakcie realizacji'}" aria-label="Przełącz status realizacji">●</button>` : ''}
        <input type="checkbox" ${t.done?'checked':''} aria-label="Oznacz jako wykonane">
      </div>
      <div class="note-content" data-act="open" role="button" tabindex="0">
        <div class="note-preview">${esc(t.text)}</div>
        <div class="note-meta">Dodano: ${dateLabel(t.created_at)} · ${String(t.text||'').length.toLocaleString('pl-PL')} znaków</div>
      </div>
      <button type="button" class="note-btn" data-act="copy" title="Kopiuj całą notatkę" aria-label="Kopiuj notatkę">⧉</button>
      <button type="button" class="note-btn del" data-act="del" title="Usuń">×</button>
    </div>`;
    let html='';
    if(!tasks.length)html='<div class="notes-empty">Brak notatek. Dodaj pierwszą powyżej.</div>';
    else{html=open.map(item).join('');if(done.length)html+='<div class="notes-sep">Wykonane</div>'+done.map(item).join('');}
    el('notesList').innerHTML=html;
  }
  function openDetail(id){
    const t=tasks.find(x=>x.id===id);if(!t)return;detailId=id;
    try{sessionStorage.setItem(noteDetailKey,String(id));}catch(_){}
    el('noteDetailTitle').textContent=t.done?'Notatka · wykonana':'Notatka';
    paintDetailDone(!!t.done);
    const doingBtn=el('noteDetailDoing');
    doingBtn.disabled=!!t.done;
    doingBtn.classList.toggle('active',!t.done&&t.status==='doing');
    doingBtn.setAttribute('aria-pressed',String(!t.done&&t.status==='doing'));
    doingBtn.title=t.done?'Ta notatka jest już wykonana':(t.status==='doing'?'Wyłącz status pracy nad tą notatką':'Oznacz, że pracujesz nad tą notatką');
    doingBtn.querySelector('span:last-child').textContent=t.done?'Wykonane':(t.status==='doing'?'W trakcie ✓':'Pracuję nad tym');
    el('noteDetailDate').textContent='Dodano: '+dateLabel(t.created_at)+(t.done_at?' · Ukończono: '+dateLabel(t.done_at):'');
    el('noteDetailBody').innerHTML=linkify(t.text);el('noteDetailEditor').value=t.text;
    el('noteDetailBody').style.display='';el('noteDetailEditor').style.display='none';
    el('noteDetailEdit').style.display='';el('noteDetailSave').style.display='none';el('noteDetailCancel').style.display='none';
    el('noteDetailBackdrop').classList.add('open');
  }
  function closeDetail(){
    el('noteDetailBackdrop').classList.remove('open');detailId=null;
    try{sessionStorage.removeItem(noteDetailKey);}catch(_){}
  }
  function setEdit(on){
    const t=tasks.find(x=>x.id===detailId);if(!t)return;
    el('noteDetailBody').style.display=on?'none':'';
    el('noteDetailEditor').style.display=on?'':'none';
    el('noteDetailEdit').style.display=on?'none':'';
    el('noteDetailSave').style.display=on?'':'none';
    el('noteDetailCancel').style.display=on?'':'none';
    if(on){el('noteDetailEditor').value=t.text;el('noteDetailEditor').focus();}
  }
  el('notesList').addEventListener('change',async e=>{
    if(e.target.type!=='checkbox')return;const row=e.target.closest('.note-item');if(!row)return;
    const id=+row.dataset.id,done=e.target.checked,t=tasks.find(x=>x.id===id);if(t)t.done=done?1:0;
    render();adopt(await call('task_toggle',{id,done:done?1:0}));
  });
  el('noteDetailDone').addEventListener('change',async e=>{
    const t=tasks.find(x=>x.id===detailId);if(!t)return;
    const id=t.id,done=e.target.checked?1:0;
    const doneLabel=el('noteDetailDoneLabel');
    paintDetailDone(!!done);
    if(done){
      doneLabel.classList.remove('just-done');
      void doneLabel.offsetWidth;
      doneLabel.classList.add('just-done');
      setTimeout(()=>doneLabel.classList.remove('just-done'),420);
    }
    const d=await call('task_toggle',{id,done});
    if(!d){paintDetailDone(!!t.done);return;}
    adopt(d);openDetail(id);
    toast(done?'Oznaczono notatkę jako zrobioną':'Przywrócono notatkę do aktywnych');
  });
  async function copyNote(text){
    try{
      if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(String(text??''));}
      else{
        const temp=document.createElement('textarea');temp.value=String(text??'');temp.setAttribute('readonly','');
        temp.style.position='fixed';temp.style.opacity='0';document.body.appendChild(temp);temp.select();
        const copied=document.execCommand('copy');temp.remove();if(!copied)throw new Error('copy_failed');
      }
      toast('Skopiowano całą notatkę');
    }catch(_){toast('Nie udało się skopiować notatki');}
  }
  el('notesList').addEventListener('click',async e=>{
    const row=e.target.closest('.note-item');if(!row)return;
    const act=e.target.closest('[data-act]')?.dataset.act;
    if(act==='doing'){const t=tasks.find(x=>x.id===+row.dataset.id);if(t&&!t.done)adopt(await call('task_status',{id:t.id,status:t.status==='doing'?'todo':'doing'}));}
    else if(act==='copy'){const t=tasks.find(x=>x.id===+row.dataset.id);if(t)await copyNote(t.text);}
    else if(act==='del'){const id=+row.dataset.id;adopt(await call('task_delete',{id}));if(detailId===id)closeDetail();}
    else if(act==='open')openDetail(+row.dataset.id);
  });
  el('notesList').addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('.note-content')){e.preventDefault();openDetail(+e.target.closest('.note-item').dataset.id);}});
  async function addTask(){
    const inp=el('notesInput'),text=inp.value.trim();if(!text)return;
    if(text.length>1000000){toast('Notatka może mieć maksymalnie 1 000 000 znaków');return;}
    const btn=el('notesAddBtn');btn.disabled=true;const d=await call('task_add',{text});btn.disabled=false;
    if(d){inp.value='';adopt(d);inp.focus();}
  }
  el('notesAddBtn').addEventListener('click',addTask);
  el('notesInput').addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();addTask();}});
  el('notesClearDone').addEventListener('click',async()=>{if(!confirm('Usunąć wszystkie wykonane notatki?'))return;adopt(await call('task_clear_done'));});
  el('noteDetailCopy').addEventListener('click',async()=>{
    const t=tasks.find(x=>x.id===detailId);if(t)await copyNote(t.text);
  });
  el('noteDetailDoing').addEventListener('click',async()=>{
    const t=tasks.find(x=>x.id===detailId);if(!t||t.done)return;
    const id=t.id,d=await call('task_status',{id,status:t.status==='doing'?'todo':'doing'});
    if(d){adopt(d);openDetail(id);}
  });
  el('noteDetailEdit').addEventListener('click',()=>setEdit(true));
  el('noteDetailCancel').addEventListener('click',()=>setEdit(false));
  el('noteDetailSave').addEventListener('click',async()=>{
    const text=el('noteDetailEditor').value.trim();if(!text){toast('Notatka nie może być pusta');return;}
    if(text.length>1000000){toast('Notatka może mieć maksymalnie 1 000 000 znaków');return;}
    const id=detailId,d=await call('task_edit',{id,text});if(d){adopt(d);openDetail(id);toast('Zapisano notatkę');}
  });
  el('noteDetailDelete').addEventListener('click',async()=>{if(!detailId)return;const d=await call('task_delete',{id:detailId});if(d){adopt(d);closeDetail();}});
  el('noteDetailClose').addEventListener('click',closeDetail);
  el('noteDetailBackdrop').addEventListener('click',e=>{if(e.target===el('noteDetailBackdrop'))closeDetail();});
  function openNotes(){
    el('notesBackdrop').classList.add('open');
    try{sessionStorage.setItem(notesOpenKey,'1');}catch(_){}
    call('task_list').then(d=>{
      adopt(d);
      let savedDetail=0;
      try{savedDetail=Number(sessionStorage.getItem(noteDetailKey)||0);}catch(_){}
      if(savedDetail>0&&tasks.some(t=>t.id===savedDetail))openDetail(savedDetail);
      else if(savedDetail>0){try{sessionStorage.removeItem(noteDetailKey);}catch(_){}}
    });
    setTimeout(()=>{if(!el('noteDetailBackdrop').classList.contains('open'))el('notesInput').focus();},50);
  }
  function closeNotes(){
    closeDetail();el('notesBackdrop').classList.remove('open');
    try{sessionStorage.removeItem(notesOpenKey);}catch(_){}
  }
  el('notesBtn').addEventListener('click',openNotes);el('notesClose').addEventListener('click',closeNotes);
  el('notesBackdrop').addEventListener('click',e=>{if(e.target===el('notesBackdrop'))closeNotes();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){if(el('noteDetailBackdrop').classList.contains('open'))closeDetail();else closeNotes();}});
  window.zmNotes={start(){if(started)return;started=true;call('task_list').then(adopt);},stop(){started=false;tasks=[];closeNotes();render();}};
  render();
  try{if(sessionStorage.getItem(notesOpenKey)==='1')openNotes();}catch(_){}
})();
</script>

<script>
/* ------------------------------------------------------------------
   PROJEKTY ROBOCZE — metasystem panelu admina.
   Lista projektów, a w każdym zadania z opisem i promptami.
   Prompt można edytować w polu tekstowym i skopiować przyciskiem.
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = str => String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const STATUS = { todo:'do zrobienia', doing:'w toku', done:'wykonane' };

  let projects = [];
  let selectedId = null;
  let editingProjectId = 0;
  let editingTaskId = null;
  let draftPrompt = null;   // { taskId, label, body }
  let started = false;

  async function call(action, payload){
    const csrf = window.zmGetCsrf ? window.zmGetCsrf() : '';
    try{
      const res = (payload === undefined && action === 'project_list')
        ? await fetch(`${API}?action=project_list`, { credentials:'include' })
        : await fetch(API, {
            method:'POST', credentials:'include',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify(Object.assign({ action, csrf }, payload || {}))
          });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d.error){ toast('Projekty: ' + (d.message || d.error)); return null; }
      return d;
    }catch(_){ toast('Projekty: błąd połączenia'); return null; }
  }

  function adopt(d){
    if(!d || !Array.isArray(d.projects)) return;
    projects = d.projects;
    if(selectedId === null || !projects.some(p => p.id === selectedId)){
      selectedId = projects.length ? projects[0].id : null;
    }
    renderSide();
    renderMain();
    updateBadge();
  }

  function selectedProject(){
    for(const p of projects){ if(p.id === selectedId) return p; }
    return null;
  }

  function updateBadge(){
    const open = projects.reduce((n, p) => n + p.tasks.filter(t => t.status !== 'done').length, 0);
    const badge = el('projectsBadge');
    if(badge){ badge.textContent = open; badge.classList.toggle('show', open > 0); }
  }

  function renderSide(){
    const list = el('projList');
    if(!list) return;
    list.innerHTML = projects.length
      ? projects.map(p => {
          const open = p.tasks.filter(t => t.status !== 'done').length;
          const total = p.tasks.length;
          return `<div class="proj-item${p.id === selectedId ? ' active' : ''}" data-id="${p.id}">
            <span class="proj-item-name">${esc(p.name)}</span>
            <span class="proj-item-count">${open}/${total}</span>
          </div>`;
        }).join('')
      : '<div class="proj-item-empty">Brak projektów.</div>';
  }

  function promptCard(p){
    return `<div class="proj-prompt" data-id="${p.id}">
      <div class="proj-prompt-head">
        <input class="proj-prompt-label" value="${esc(p.label)}" maxlength="160" placeholder="Nazwa promptu (np. Implementacja)">
        <div class="proj-prompt-tools">
          <button type="button" class="tool-btn mini" data-act="prompt-copy" title="Skopiuj treść promptu (razem z edycjami)">Kopiuj</button>
          <button type="button" class="tool-btn mini" data-act="prompt-save" title="Zapisz zmiany w promptcie">Zapisz</button>
          <button type="button" class="note-btn del" data-act="prompt-del" title="Usuń prompt">×</button>
        </div>
      </div>
      <textarea class="proj-prompt-body" rows="8" spellcheck="false">${esc(p.body)}</textarea>
    </div>`;
  }

  function draftCard(){
    return `<div class="proj-prompt draft">
      <div class="proj-prompt-head">
        <input class="proj-prompt-label" id="projPromptNewLabel" value="${esc(draftPrompt.label)}" maxlength="160" placeholder="Nazwa promptu">
        <div class="proj-prompt-tools">
          <button type="button" class="tool-btn mini" data-act="prompt-new-cancel">Anuluj</button>
          <button type="button" class="tool-btn mini" data-act="prompt-new-save">Dodaj</button>
        </div>
      </div>
      <textarea class="proj-prompt-body" id="projPromptNewBody" rows="8" spellcheck="false" placeholder="Wklej lub napisz prompt…">${esc(draftPrompt.body)}</textarea>
    </div>`;
  }

  function taskCard(t){
    if(t.id === editingTaskId){
      const opts = ['todo','doing','done'].map(s => `<option value="${s}"${t.status === s ? ' selected' : ''}>${STATUS[s]}</option>`).join('');
      return `<div class="proj-task editing" data-id="${t.id}">
        <input type="text" class="proj-task-edit-title" id="projTaskEditTitle" value="${esc(t.title)}" maxlength="200">
        <textarea class="proj-task-edit-desc" id="projTaskEditDesc" rows="3" placeholder="Opis funkcji…">${esc(t.description || '')}</textarea>
        <div class="proj-task-edit-row">
          <label>Status <select id="projTaskEditStatus">${opts}</select></label>
          <label>Priorytet <input type="number" id="projTaskEditPriority" min="0" max="5" value="${t.priority}"></label>
        </div>
        <div class="proj-form-actions">
          <button type="button" class="tool-btn" data-act="task-cancel">Anuluj</button>
          <button type="button" class="tool-btn" data-act="task-save">Zapisz zadanie</button>
        </div>
      </div>`;
    }
    const done = t.status === 'done';
    return `<div class="proj-task${done ? ' done' : ''}" data-id="${t.id}">
      <div class="proj-task-head">
        <input type="checkbox" class="proj-task-check" ${done ? 'checked' : ''} aria-label="Wykonane">
        <div class="proj-task-titles">
          <span class="proj-task-title" data-act="task-edit">${esc(t.title)}</span>
          ${t.description ? `<span class="proj-task-desc">${esc(t.description)}</span>` : ''}
          <span><span class="proj-status proj-status-${t.status}">${STATUS[t.status] || t.status}</span></span>
        </div>
        <div class="proj-task-tools">
          <button type="button" class="note-btn" data-act="task-edit" title="Edytuj zadanie">✎</button>
          <button type="button" class="note-btn del" data-act="task-del" title="Usuń zadanie">×</button>
        </div>
      </div>
      <div class="proj-prompts">
        ${t.prompts.map(promptCard).join('')}
        ${draftPrompt && draftPrompt.taskId === t.id ? draftCard() : ''}
        <button type="button" class="proj-prompt-add" data-act="prompt-new">+ Prompt</button>
      </div>
    </div>`;
  }

  function renderMain(){
    const p = selectedProject();
    el('projEmpty').style.display = p ? 'none' : '';
    el('projDetail').style.display = p ? '' : 'none';
    if(!p) return;
    el('projTitle').textContent = p.name;
    el('projDetailDesc').textContent = p.description || '';
    const st = el('projStatus');
    st.textContent = { active:'aktywny', paused:'wstrzymany', done:'ukończony' }[p.status] || p.status;
    st.className = 'proj-status proj-status-' + p.status;
    const wrap = el('projTasks');
    wrap.innerHTML = p.tasks.length
      ? p.tasks.map(taskCard).join('')
      : '<div style="padding:18px 0;color:var(--ink-dim);">Brak zadań — dodaj pierwsze powyżej.</div>';
    if(draftPrompt){ const body = el('projPromptNewBody'); if(body) body.focus(); }
  }

  async function copyText(text){
    try{
      if(navigator.clipboard && window.isSecureContext){
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      toast('Skopiowano prompt do schowka');
    }catch(_){ toast('Nie udało się skopiować'); }
  }

  // --- formularz projektu ---
  function openProjectForm(id){
    editingProjectId = id || 0;
    const p = id ? selectedProject() : null;
    el('projName').value = p ? p.name : '';
    el('projDesc').value = (p && p.description) ? p.description : '';
    el('projForm').style.display = '';
    el('projName').focus();
  }
  function closeProjectForm(){ editingProjectId = 0; el('projForm').style.display = 'none'; }

  async function saveProjectForm(){
    const name = el('projName').value.trim();
    if(!name){ toast('Podaj nazwę projektu'); return; }
    const description = el('projDesc').value.trim();
    const d = editingProjectId > 0
      ? await call('project_edit', { id: editingProjectId, name, description })
      : await call('project_add', { name, description });
    if(d){ closeProjectForm(); adopt(d); }
  }

  // --- zdarzenia: lista projektów ---
  el('projList').addEventListener('click', e => {
    const item = e.target.closest('.proj-item');
    if(!item) return;
    selectedId = +item.dataset.id;
    editingTaskId = null; draftPrompt = null;
    renderSide(); renderMain();
  });
  el('projAddBtn').addEventListener('click', () => openProjectForm(0));
  el('projEditBtn').addEventListener('click', () => { if(selectedId) openProjectForm(selectedId); });
  el('projFormCancel').addEventListener('click', closeProjectForm);
  el('projFormSave').addEventListener('click', saveProjectForm);
  el('projDeleteBtn').addEventListener('click', async () => {
    const p = selectedProject();
    if(!p) return;
    if(!confirm('Usunąć projekt „' + p.name + '” wraz ze wszystkimi zadaniami i promptami?')) return;
    adopt(await call('project_delete', { id: p.id }));
  });

  // --- zdarzenia: zadania ---
  async function addTask(){
    const p = selectedProject();
    if(!p) return;
    const inp = el('projTaskInput');
    const title = inp.value.trim();
    if(!title) return;
    inp.value = '';
    inp.focus();
    adopt(await call('project_task_add', { project_id: p.id, title }));
  }
  el('projTaskAddBtn').addEventListener('click', addTask);
  el('projTaskInput').addEventListener('keydown', e => {
    if(e.key === 'Enter'){ e.preventDefault(); addTask(); }
  });

  el('projTasks').addEventListener('change', async e => {
    if(!e.target.classList.contains('proj-task-check')) return;
    const row = e.target.closest('.proj-task');
    adopt(await call('project_task_toggle', { id: +row.dataset.id, done: e.target.checked ? 1 : 0 }));
  });

  el('projTasks').addEventListener('click', async e => {
    const btn = e.target.closest('[data-act]');
    if(!btn) return;
    const act = btn.dataset.act;
    const taskRow = btn.closest('.proj-task');

    if(act === 'task-edit'){ editingTaskId = +taskRow.dataset.id; renderMain(); return; }
    if(act === 'task-cancel'){ editingTaskId = null; renderMain(); return; }
    if(act === 'task-del'){
      if(!confirm('Usunąć to zadanie wraz z jego promptami?')) return;
      adopt(await call('project_task_delete', { id: +taskRow.dataset.id }));
      return;
    }
    if(act === 'task-save'){
      const title = el('projTaskEditTitle').value.trim();
      if(!title){ toast('Podaj tytuł zadania'); return; }
      const d = await call('project_task_edit', {
        id: +taskRow.dataset.id,
        title,
        description: el('projTaskEditDesc').value.trim(),
        status: el('projTaskEditStatus').value,
        priority: +el('projTaskEditPriority').value || 0
      });
      if(d){ editingTaskId = null; adopt(d); }
      return;
    }

    if(act === 'prompt-new'){
      draftPrompt = { taskId: +taskRow.dataset.id, label:'', body:'' };
      renderMain();
      return;
    }
    if(act === 'prompt-new-cancel'){ draftPrompt = null; renderMain(); return; }
    if(act === 'prompt-new-save'){
      const body = el('projPromptNewBody').value.trim();
      if(!body){ toast('Prompt nie może być pusty'); return; }
      const d = await call('project_prompt_add', {
        task_id: draftPrompt.taskId,
        label: el('projPromptNewLabel').value.trim(),
        body
      });
      if(d){ draftPrompt = null; adopt(d); }
      return;
    }

    if(act === 'prompt-copy'){
      const block = btn.closest('.proj-prompt');
      copyText(block.querySelector('.proj-prompt-body').value);
      return;
    }
    if(act === 'prompt-save'){
      const block = btn.closest('.proj-prompt');
      const body = block.querySelector('.proj-prompt-body').value.trim();
      if(!body){ toast('Prompt nie może być pusty'); return; }
      adopt(await call('project_prompt_edit', {
        id: +block.dataset.id,
        label: block.querySelector('.proj-prompt-label').value.trim(),
        body
      }));
      return;
    }
    if(act === 'prompt-del'){
      const block = btn.closest('.proj-prompt');
      if(!confirm('Usunąć ten prompt?')) return;
      adopt(await call('project_prompt_delete', { id: +block.dataset.id }));
      return;
    }
  });

  window.zmProjects = {
    start(){
      if(started) return;
      started = true;
      call('project_list').then(adopt);   // liczba otwartych zadań na przycisku
    },
    refresh(){
      if(!started) return;
      call('project_list').then(adopt);
    },
    stop(){
      started = false;
      closeProjectForm();
      editingTaskId = null; draftPrompt = null;
      projects = []; selectedId = null;
      renderSide(); renderMain();
      const badge = el('projectsBadge');
      if(badge){ badge.textContent = '0'; badge.classList.remove('show'); }
    }
  };
})();
</script>

<script>
/* ===============================================================
   POWIADOMIENIA — ALERTY
   Okno z dwiema zakładkami: alerty wymagające reakcji oraz konfiguracja
   typów. Odczyt po GET, zapis po POST z tokenem CSRF — ta sama konwencja,
   której trzymają się pozostałe okna panelu.
   =============================================================== */
(function(){
  const PAPI = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const csrf = () => (window.zmGetCsrf ? window.zmGetCsrf() : '');

  // stan współdzielony z zakładką konfiguracji (dopisana niżej w tym samym
  // <script>) — trzymamy go na window, żeby obie części gadały tym samym
  window.zmNotifyState = window.zmNotifyState || { alerts: [], types: [], started: false, tab: 'alerts' };
  const state = window.zmNotifyState;

  const ICONS = {
    'user-plus':'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
    'heart':'<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    'comment':'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'reply':'<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v2"/>',
    'mail':'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
    'camera':'<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    'check':'<path d="M20 6 9 17l-5-5"/>',
    'cross':'<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
    'star':'<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
    'flag':'<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    'megaphone':'<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'lightbulb':'<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
    'activity':'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    'rocket':'<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
    'package':'<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/>',
    'server':'<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
    'bell':'<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
  };
  function icon(name){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         + (ICONS[name] || ICONS.bell) + '</svg>';
  }

  function when(ts){
    try{
      const ms = Number(ts) || 0;
      if(ms <= 0) return '';
      return new Date(ms).toLocaleString('pl-PL', {
        day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'
      });
    }catch(_){ return ''; }
  }

  /*
   * Dwie osobne funkcje zamiast jednej „call(action, payload)".
   *
   * Poprzednia wersja wybierała metodę HTTP na podstawie tego, CZY PODANO
   * PAYLOAD: `call('x', {limit:100})` szedł POST-em. Dla odczytów z GET-em
   * (notify_admin_alerts) kończyło się to `unknown_action` i czerwonym
   * komunikatem w panelu. Dwa trywająco nazwane wywołania wykluczają ten błąd
   * przy każdym kolejnym dodaniu akcji.
   */

  /** Odczyt: GET, parametry w adresie. Nigdy nie zmienia stanu. */
  async function get(action, params){
    const q = new URLSearchParams(params || {});
    q.set('action', action);
    try{
      const res = await fetch(PAPI + '?' + q.toString(), { credentials:'include' });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d.error){ toast(d.message || ('Powiadomienia: ' + d.error)); return null; }
      return d;
    }catch(_){ toast('Powiadomienia: błąd połączenia'); return null; }
  }

  /** Zapis: POST z tokenem CSRF. */
  async function post(action, payload){
    try{
      const res = await fetch(PAPI, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify(Object.assign({ action, csrf: csrf() }, payload || {}))
      });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d.error){ toast(d.message || ('Powiadomienia: ' + d.error)); return null; }
      return d;
    }catch(_){ toast('Powiadomienia: błąd połączenia'); return null; }
  }
  /* ------------------------------------------------------------- ALERTY */

  /**
   * Link „pokaż" prowadzi prosto do miejsca, którego dotyczy alert:
   * zgłoszony komentarz → zdjęcie z podświetlonym komentarzem,
   * zgłoszenie zdjęcia → samo zdjęcie (po moderacji prowadzi do galerii).
   */
  function alertHref(a){
    if(a.refType === 'comment' && a.refId) return 'index.php?comment=' + encodeURIComponent(a.refId);
    if(a.refType === 'photo'   && a.refId) return 'index.php?photo=p' + encodeURIComponent(a.refId);
    return '';
  }

  function renderAlerts(){
    const list = state.alerts;
    const unread = list.filter(a => !a.isRead).length;
    const badge = el('notifyBadge');
    badge.textContent = unread;
    badge.classList.toggle('show', unread > 0);
    el('notifyProgress').textContent = list.length
      ? (unread ? unread + ' nowych z ' + list.length : list.length + ' alertów')
      : '';

    if(!list.length){
      el('notifyAlertList').innerHTML =
        '<div class="notes-empty">Brak alertów — wszystko obsłużone.</div>';
      return;
    }

    el('notifyAlertList').innerHTML = list.map(a => {
      const href = alertHref(a);
      const who  = a.actor ? '<b>' + esc(a.actor.name) + '</b> — ' : '';
      return '<div class="notify-alert' + (a.isRead ? '' : ' is-new') + '" data-id="' + a.id + '">'
        + '<span class="notify-alert-ico">' + icon(a.icon) + '</span>'
        + '<div class="notify-alert-body">'
          + '<div class="notify-alert-title">' + esc(a.label) + '</div>'
          + '<div class="notify-alert-text">' + who + esc(a.body) + '</div>'
          + '<div class="notify-alert-when"><span>' + esc(when(a.ts)) + '</span>'
            + (a.actor && a.actor.url ? '<a href="' + esc(a.actor.url) + '" target="_blank" rel="noopener">profil</a>' : '')
            + (href ? '<a href="' + esc(href) + '" target="_blank" rel="noopener">pokaż</a>' : '')
          + '</div>'
        + '</div>'
        + '<div class="notify-act">'
          + '<button type="button" class="modal-cancel" data-act="read" title="'
            + (a.isRead ? 'Oznacz jako nieobsłużone' : 'Oznacz jako obsłużone') + '">'
            + (a.isRead ? '↺' : '✓') + '</button>'
          + '<button type="button" class="modal-cancel" data-act="del" title="Usuń alert">×</button>'
        + '</div>'
      + '</div>';
    }).join('');
  }

  async function loadAlerts(){
    const d = await get('notify_admin_alerts', { limit: 100 });
    if(!d) return;
    state.alerts = d.items || [];
    renderAlerts();
  }

  async function loadConfig(){
    const d = await get('notify_config');
    if(!d) return;
    state.types = d.types || [];
    renderConfig();
  }

  el('notifyAlertList').addEventListener('click', async e => {
    const btn = e.target.closest('button[data-act]');
    if(!btn) return;
    const id  = Number(btn.closest('.notify-alert').dataset.id);
    const act = btn.dataset.act;
    const it  = state.alerts.find(x => x.id === id);
    btn.disabled = true;

    if(act === 'read'){
      const d = await post('notify_alert_read', { id, read: !(it && it.isRead) });
      if(!d){ btn.disabled = false; return; }
      if(it) it.isRead = !it.isRead;
      renderAlerts();
      return;
    }

    if(act === 'del'){
      // serwer czyści tylko alerty oznaczone jako obsłużone, więc nieobsłużony
      // najpierw oznaczamy, potem czyścimy — dwie operacje zamiast jednej
      if(it && !it.isRead) await post('notify_alert_read', { id, read: true });
      await post('notify_alerts_handle_all', { mode: 'clear' });
      loadAlerts();
    }
  });

  el('notifyReadAll').addEventListener('click', async () => {
    const d = await post('notify_alerts_handle_all', { mode: 'read' });
    if(!d) return;
    state.alerts.forEach(a => { a.isRead = true; });
    renderAlerts();
    toast('Oznaczono wszystkie alerty jako obsłużone.');
  });

  el('notifyClearHandled').addEventListener('click', async () => {
    const d = await post('notify_alerts_handle_all', { mode: 'clear' });
    if(!d) return;
    loadAlerts();
    toast('Usunięto ' + d.affected + ' alertów.');
  });
  /* ----------------------------------------------------- KONFIGURACJA TYPs */

  function renderConfig(){
    if(!state.types.length){
      el('notifyCfgList').innerHTML =
        '<div class="notes-empty">Brak typów powiadomień w bazie.</div>';
      return;
    }
    el('notifyCfgList').innerHTML = state.types.map(t => {
      // skrót pokazuje, gdzie typ trafia TERAZ — po zapisaniu z samego
      // formularza nie da się tego wyczytać, a to najważniejsza informacja
      const where = [
        t.to_owner     ? 'właścicielowi' : null,
        t.to_followers ? 'obserwującym'  : null,
        t.to_admin     ? 'do panelu'      : null,
      ].filter(Boolean);
      const tag = t.user_manage ? 'użytkownik może wyłączyć' : 'tylko administracja';
      return '<div class="notify-cfg' + (t.enabled ? '' : ' is-off') + '" data-type="' + esc(t.type) + '">'
        + '<div class="notify-cfg-main">'
          + '<input type="text" class="cfg-label" value="' + esc(t.label) + '" maxlength="80" aria-label="Nazwa typu powiadomienia">'
          + '<div class="notify-cfg-desc">' + esc(t.desc) + '</div>'
          + '<div class="notify-cfg-targets">'
            + '<label class="notify-chk"><input type="checkbox" class="cfg-enabled"' + (t.enabled ? ' checked' : '') + '> włączony</label>'
            + '<label class="notify-chk"><input type="checkbox" class="cfg-owner"' + (t.to_owner ? ' checked' : '') + '> właścicielowi</label>'
            + '<label class="notify-chk"><input type="checkbox" class="cfg-followers"' + (t.to_followers ? ' checked' : '') + '> obserwującym</label>'
            + '<label class="notify-chk"><input type="checkbox" class="cfg-admin"' + (t.to_admin ? ' checked' : '') + '> do panelu</label>'
          + '</div>'
          + '<div class="notify-cfg-desc">'
            + 'teraz: ' + (t.enabled ? esc(where.join(', ') || 'nigdzie — włączony, ale bez odbiorcy') : 'wyłączony')
            + ' · ' + esc(tag) + (t.total ? ' · wysłano: ' + t.total : '')
          + '</div>'
        + '</div>'
        + '<button type="button" class="notify-cfg-save" data-act="save-cfg">zapisz</button>'
      + '</div>';
    }).join('');
  }

  el('notifyCfgList').addEventListener('click', async e => {
    const btn = e.target.closest('[data-act="save-cfg"]');
    if(!btn) return;
    const row = btn.closest('.notify-cfg');
    const q = sel => row.querySelector(sel);
    const payload = {
      type: row.dataset.type,
      label: q('.cfg-label').value,
      enabled: q('.cfg-enabled').checked,
      to_owner: q('.cfg-owner').checked,
      to_followers: q('.cfg-followers').checked,
      to_admin: q('.cfg-admin').checked,
    };

    // serwer waliduje to samo, ale sprawdzamy tu, żeby administrator widział
    // powód bez podróży na serwer — komunikat jest wtedy identyczny
    if(!payload.label.trim()){
      toast('Nazwa typu nie może być pusta.');
      return;
    }
    if(payload.enabled && !payload.to_owner && !payload.to_followers && !payload.to_admin){
      toast('Włączony typ musi trafić do kogoś — zaznacz co najmniej jednego odbiorcę.');
      return;
    }

    btn.disabled = true;
    const d = await post('notify_type_save', payload);
    btn.disabled = false;
    if(!d) return;
    await loadConfig();
    toast('Zapisano konfigurację typu.');
  });
  /* ----------------------------------------------------------- OGŁOSZENIE */

  el('notifyBroadcastBtn').addEventListener('click', async () => {
    const ta = el('notifyBroadcastText');
    const body = ta.value.trim();
    if(!body){
      toast('Wpisz treść ogłoszenia.');
      return;
    }
    if(!confirm('Wysłać ogłoszenie do wszystkich użytkowników? Tego nie da się cofnąć.')) return;

    const btn = el('notifyBroadcastBtn');
    btn.disabled = true;
    btn.textContent = 'wysyłanie…';
    const d = await post('notify_broadcast', { body });
    btn.disabled = false;
    btn.textContent = 'wyślij do wszystkich';
    if(!d) return;

    ta.value = '';
    el('notifyBroadcastInfo').textContent =
      'Wysłano do ' + d.sent + ' użytkowników'
      + (d.skipped ? ', pominięto ' + d.skipped + ' (typ wyłączony u nich).' : '.');
    toast('Ogłoszenie wysłane do ' + d.sent + ' osób.');
  });

  /* ----------------------------------------------------------------- OKNO */

  function showTab(name){
    state.tab = name;
    document.querySelectorAll('#notifyBackdrop .notify-tab').forEach(b => {
      const on = b.dataset.ntab === name;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('#notifyBackdrop [data-npane]').forEach(p => {
      p.hidden = (p.dataset.npane !== name);
    });
    // konfigurację wczytujemy dopiero przy pierwszym otwarciu zakładki —
    // okno alertów jest częstsze i nie warto płacić za dodatkowy odczyt
    if(name === 'config' && !state.types.length) loadConfig();
  }

  document.querySelectorAll('#notifyBackdrop .notify-tab').forEach(b => {
    b.addEventListener('click', () => showTab(b.dataset.ntab));
  });

  function openNotify(){
    el('notifyBackdrop').classList.add('open');
    showTab(state.tab);
    loadAlerts();
  }
  function closeNotify(){ el('notifyBackdrop').classList.remove('open'); }

  el('notifyBtn').addEventListener('click', openNotify);
  el('notifyCloseAlerts').addEventListener('click', closeNotify);
  el('notifyCloseConfig').addEventListener('click', closeNotify);
  el('notifyBackdrop').addEventListener('click', e => {
    if(e.target === el('notifyBackdrop')) closeNotify();
  });
  // okno ma własne przyciski „Zamknij", więc pilnujemy tylko tego, żeby
  // klawisz nie zamykał równocześnie notatnika i okna zgłoszeń
  el('notifyBackdrop').addEventListener('keydown', e => {
    if(e.key === 'Escape'){ e.stopPropagation(); closeNotify(); }
  });

  /*
   * Licznik alertów na przycisku. Wywoływane z głównego skryptu panelu
   * (window.zmNotify.start()), tak jak dla Notatnika i Zgłoszeń — dzięki
   * temu plakietka znika, gdy administrator przestaje widzieć panel.
   */
  window.zmNotify = {
    start(){
      if(state.started) return;
      state.started = true;
      loadAlerts();
    },
    stop(){
      state.started = false;
      closeNotify();
      renderAlerts();
    }
  };

  renderAlerts();
})();
</script>

<script>
/* ===============================================================
   ZABLOKOWANE NAZWY UŻYTKOWNIKA
   Lista loginów, których rejestracja nie przyjmie. Panel to miejsce,
   w którym administrator tę listę w całości kontroluje — serwer tylko
   wykonuje to, co jest w tabeli `blocked_usernames`.
   =============================================================== */
(function(){
  const PAPI = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  let items = [];

  async function call(action, payload){
    const csrf = window.zmGetCsrf ? window.zmGetCsrf() : '';
    try{
      const res = payload === undefined
        ? await fetch(`${PAPI}?action=${action}`, { credentials:'include' })
        : await fetch(PAPI, {
            method:'POST', credentials:'include',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify(Object.assign({ action, csrf }, payload))
          });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d.error){ toast(d.message || ('Lista nazw: ' + d.error)); return null; }
      return d;
    }catch(_){ toast('Lista nazw: błąd połączenia'); return null; }
  }

  function render(){
    const badge = el('blockedBadge');
    badge.textContent = items.length;
    badge.classList.toggle('show', items.length > 0);
    el('blockedProgress').textContent = items.length ? items.length + ' pozycji' : '';

    if(!items.length){
      el('blockedList').innerHTML =
        '<div class="notes-empty">Lista jest pusta — rejestracja przyjmuje każdy login.</div>';
      return;
    }
    el('blockedList').innerHTML = items.map(it =>
      `<div class="note-item" data-id="${it.id}">
         <span class="note-text">${esc(it.name)}</span>
         ${it.in_use ? '<span class="blocked-inuse" title="Taki login już istnieje — pozycję można usunąć, nazwa zostanie w bazie">w użyciu: ${it.in_use}</span>' : ''}
         <button type="button" class="note-btn del" data-act="del" title="Usuń z listy">×</button>
       </div>`
    ).join('');
  }

  function adopt(d){
    if(!d || !Array.isArray(d.items)) return;
    items = d.items;
    render();
  }

  async function add(){
    const inp = el('blockedInput');
    const name = inp.value.trim();
    if(!name) return;
    inp.value = '';
    inp.focus();
    const d = await call('blocked_add', { name });
    if(d) toast('Dodano: ' + d.name);
    adopt(d);
  }

  el('blockedAddBtn').addEventListener('click', add);
  el('blockedInput').addEventListener('keydown', e => {
    if(e.key === 'Enter'){ e.preventDefault(); add(); }
  });
  el('blockedList').addEventListener('click', async e => {
    const btn = e.target.closest('[data-act="del"]');
    if(!btn) return;
    const id = +btn.closest('.note-item').dataset.id;
    items = items.filter(x => x.id !== id);
    render();
    adopt(await call('blocked_delete', { id }));
  });

  function openBlocked(){
    el('blockedBackdrop').classList.add('open');
    call('blocked_list').then(adopt);
    setTimeout(() => el('blockedInput').focus(), 50);
  }
  function closeBlocked(){ el('blockedBackdrop').classList.remove('open'); }
  el('blockedBtn').addEventListener('click', openBlocked);
  el('blockedClose').addEventListener('click', closeBlocked);
  el('blockedBackdrop').addEventListener('click', e => { if(e.target === el('blockedBackdrop')) closeBlocked(); });
  document.addEventListener('keydown', e => { if(e.key === 'Escape') closeBlocked(); });

  // Plakietka z liczbą pozycji — ta sama zasada co przy Notatniku.
  call('blocked_list').then(adopt);
})();

/* ===============================================================
   WULGARYZMY W KOMENTARZACH
   Lista wyrazów, które comments.php odrzuca przy dodaniu komentarza.
   Panel to miejsce, w którym administrator tę listę w całości kontroluje —
   serwer tylko wykonuje to, co jest w tabeli `blocked_words`.
   =============================================================== */
(function(){
  const PAPI = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  let items = [];

  async function call(action, payload){
    const csrf = window.zmGetCsrf ? window.zmGetCsrf() : '';
    try{
      const res = payload === undefined
        ? await fetch(`${PAPI}?action=${action}`, { credentials:'include' })
        : await fetch(PAPI, {
            method:'POST', credentials:'include',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify(Object.assign({ action, csrf }, payload))
          });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d.error){ toast(d.message || ('Lista wulgaryzmów: ' + d.error)); return null; }
      return d;
    }catch(_){ toast('Lista wulgaryzmów: błąd połączenia'); return null; }
  }

  function render(){
    const badge = el('wordsBadge');
    badge.textContent = items.length;
    badge.classList.toggle('show', items.length > 0);
    el('wordsProgress').textContent = items.length ? items.length + ' pozycji' : '';

    if(!items.length){
      el('wordsList').innerHTML =
        '<div class="notes-empty">Lista jest pusta — komentarze przepuszczają każdy wyraz.</div>';
      return;
    }
    el('wordsList').innerHTML = items.map(it =>
      `<div class="note-item" data-id="${it.id}">
         <span class="note-text">${esc(it.word)}</span>
         <button type="button" class="note-btn del" data-act="del" title="Usuń z listy">×</button>
       </div>`
    ).join('');
  }

  function adopt(d){
    if(!d || !Array.isArray(d.items)) return;
    items = d.items;
    render();
  }

  async function add(){
    const inp = el('wordsInput');
    const word = inp.value.trim();
    if(!word) return;
    inp.value = '';
    inp.focus();
    const d = await call('blocked_words_add', { word });
    if(d) toast('Dodano: ' + d.word);
    adopt(d);
  }

  el('wordsAddBtn').addEventListener('click', add);
  el('wordsInput').addEventListener('keydown', e => {
    if(e.key === 'Enter'){ e.preventDefault(); add(); }
  });
  el('wordsList').addEventListener('click', async e => {
    const btn = e.target.closest('[data-act="del"]');
    if(!btn) return;
    const id = +btn.closest('.note-item').dataset.id;
    items = items.filter(x => x.id !== id);
    render();
    adopt(await call('blocked_words_delete', { id }));
  });

  function openWords(){
    el('wordsBackdrop').classList.add('open');
    call('blocked_words_list').then(adopt);
    setTimeout(() => el('wordsInput').focus(), 50);
  }
  function closeWords(){ el('wordsBackdrop').classList.remove('open'); }
  el('wordsBtn').addEventListener('click', openWords);
  el('wordsClose').addEventListener('click', closeWords);
  el('wordsBackdrop').addEventListener('click', e => { if(e.target === el('wordsBackdrop')) closeWords(); });
  document.addEventListener('keydown', e => { if(e.key === 'Escape') closeWords(); });

  // Plakietka z liczbą pozycji — ta sama zasada co przy Zablokowanych nazwach.
  call('blocked_words_list').then(adopt);
})();

/* ===============================================================
   ZGŁOSZENIA KOMENTARZY
   Lista zgłoszeń od czytelników (także bez konta). Administrator
   reaguje od razu albo otwiera zdjęcie z przewinięciem do
   zgłoszonego komentarza (deep link obsługuje index.php).
   =============================================================== */
(function(){
  const AAPI = 'admin-api.php';
  const CAPI = 'comments.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const csrf = () => (window.zmGetCsrf ? window.zmGetCsrf() : '');
  let items = [];       // zgłoszenia komentarzy
  let photoItems = [];  // zgłoszenia zdjęć

  // Odczyt idzie po GET (tak jak lista wulgaryzmów powyżej), a zmiany po POST
  // z tokenem CSRF — ta sama konwencja, której trzyma się reszta panelu.
  async function callApi(action, payload){
    try{
      const res = payload === undefined
        ? await fetch(AAPI + '?action=' + encodeURIComponent(action), { credentials:'include' })
        : await fetch(AAPI, {
            method:'POST', credentials:'include',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify(Object.assign({ action, csrf: csrf() }, payload))
          });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d.error){ toast(d.message || ('Zgłoszenia: ' + d.error)); return null; }
      return d;
    }catch(_){ toast('Zgłoszenia: błąd połączenia'); return null; }
  }

  // Usuwanie komentarza obsługuje comments.php (ta sama funkcja, której używa
  // moderacja przy zdjęciu) — jeden kod dla obu miejsc, bez powtórzeń.
  async function callComments(payload){
    try{
      const res = await fetch(CAPI, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify(Object.assign({ csrf: csrf() }, payload))
      });
      const d = await res.json().catch(() => null);
      if(!res.ok){ toast((d && d.message) || ('Komentarz: status ' + res.status)); return null; }
      return d || {};
    }catch(_){ toast('Komentarz: błąd połączenia'); return null; }
  }

  function when(ts){
    try{ return new Date(ts).toLocaleString('pl-PL', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }); }
    catch(_){ return ''; }
  }

  function reporterLabel(it){
    return it.reporter_guest ? esc(it.reporter) + ' (bez konta)' : esc(it.reporter);
  }

  function renderComments(){
    if(!items.length){
      el('reportsList').innerHTML =
        '<div class="notes-empty">Brak otwartych zgłoszeń komentarzy — wszystko obsłużone.</div>';
      return;
    }
    el('reportsList').innerHTML = items.map(it => {
      const link = it.comment_missing || !it.photo_id
        ? ''
        : 'index.php?photo=' + encodeURIComponent(it.photo_id)
          + '&comment=' + encodeURIComponent(it.comment_id);
      return '<div class="report-item" data-id="' + it.id + '">'
        + '<div class="report-head">'
          + '<span class="report-reason">' + esc(it.reason_label) + '</span>'
          + '<span class="report-when">' + esc(when(it.created_at)) + '</span>'
          + '<span class="report-by">zgłasza: ' + reporterLabel(it) + '</span>'
        + '</div>'
        + '<div class="report-text' + (it.comment_missing ? ' is-missing' : '') + '">'
          + (it.comment_missing ? '(komentarz już usunięty)' : esc(it.comment_text))
        + '</div>'
        + (it.note ? '<div class="report-note">' + esc(it.note) + '</div>' : '')
        + '<div class="report-actions">'
          + (link ? '<a href="' + link + '" target="_blank" rel="noopener">Pokaż</a>' : '')
          + (it.comment_missing ? '' : '<button type="button" class="danger" data-act="del">Usuń komentarz</button>')
          + '<button type="button" data-act="dismiss">Odrzuć</button>'
        + '</div>'
      + '</div>';
    }).join('');
  }

  function renderPhotos(){
    if(!photoItems.length){
      el('reportsPhotosList').innerHTML =
        '<div class="notes-empty">Brak otwartych zgłoszeń zdjęć — wszystko obsłużone.</div>';
      return;
    }
    el('reportsPhotosList').innerHTML = photoItems.map(it => {
      const missing = !!it.photo_missing;
      const link = missing ? '' : (it.url || ('index.php?photo=p' + it.photo_id));
      const sub = [];
      if(it.author) sub.push('autor: ' + esc(it.author));
      if(it.moderation_status && it.moderation_status !== 'approved') sub.push(esc(it.moderation_status));
      const thumb = (!missing && it.thumb)
        ? '<a class="report-thumb" href="' + link + '" target="_blank" rel="noopener"><img src="' + esc(it.thumb) + '" alt="" loading="lazy"></a>'
        : '';
      return '<div class="report-item" data-id="' + it.id + '">'
        + '<div class="report-head">'
          + '<span class="report-reason">' + esc(it.reason_label) + '</span>'
          + '<span class="report-when">' + esc(when(it.created_at)) + '</span>'
          + '<span class="report-by">zgłasza: ' + reporterLabel(it) + '</span>'
        + '</div>'
        + '<div class="report-photo">'
          + thumb
          + '<div>'
            + '<div class="report-text' + (missing ? ' is-missing' : '') + '">'
              + (missing ? '(zdjęcie już usunięte)' : esc(it.title))
            + '</div>'
            + (sub.length ? '<div class="report-sub">' + sub.join(' · ') + '</div>' : '')
            + (it.place ? '<div class="report-sub">' + esc(it.place) + '</div>' : '')
          + '</div>'
        + '</div>'
        + (it.note ? '<div class="report-note">' + esc(it.note) + '</div>' : '')
        + '<div class="report-actions">'
          + (link ? '<a href="' + link + '" target="_blank" rel="noopener">Pokaż</a>' : '')
          + (missing ? '' : '<button type="button" class="danger" data-act="del">Usuń zdjęcie</button>')
          + '<button type="button" data-act="dismiss">Odrzuć</button>'
        + '</div>'
      + '</div>';
    }).join('');
  }

  function renderBadges(){
    const total = items.length + photoItems.length;
    const badge = el('reportsBadge');
    badge.textContent = total;
    badge.classList.toggle('show', total > 0);
    el('reportsCommentsBadge').textContent = items.length;
    el('reportsPhotosBadge').textContent = photoItems.length;
    el('reportsProgress').textContent = total ? total + ' do obejrzenia' : '';
  }

  function renderAll(){ renderBadges(); renderComments(); renderPhotos(); }

  function adopt(d){
    if(!d || !Array.isArray(d.items)) return;
    items = d.items;
    renderAll();
  }

  function adoptPhotos(d){
    if(!d || !Array.isArray(d.items)) return;
    photoItems = d.items;
    renderAll();
  }

  el('reportsList').addEventListener('click', async e => {
    const btn = e.target.closest('button[data-act]');
    if(!btn) return;
    const id = +btn.closest('.report-item').dataset.id;
    btn.disabled = true;

    if(btn.dataset.act === 'del'){
      if(!window.confirm('Usunąć zgłoszony komentarz razem z odpowiedziami?')){
        btn.disabled = false;
        return;
      }
      const it = items.find(x => x.id === id);
      if(!it){ btn.disabled = false; return; }
      const done = await callComments({ action:'admin_delete', id: it.comment_id });
      if(!done){ btn.disabled = false; return; }
      // komentarz zniknął, więc zgłoszenie zamykamy jako obsłużone
      const d = await callApi('comment_reports_handle', { ids:[id], status:'resolved' });
      if(d) toast('Komentarz usunięty.');
      adopt(d);
      return;
    }

    const d = await callApi('comment_reports_handle', { ids:[id], status:'dismissed' });
    if(d) toast('Zgłoszenie odrzucone.');
    adopt(d);
  });

  el('reportsPhotosList').addEventListener('click', async e => {
    const btn = e.target.closest('button[data-act]');
    if(!btn) return;
    const id = +btn.closest('.report-item').dataset.id;
    btn.disabled = true;

    if(btn.dataset.act === 'del'){
      if(!window.confirm('Usunąć zgłoszone zdjęcie razem z komentarzami i polubieniami?')){
        btn.disabled = false;
        return;
      }
      const it = photoItems.find(x => x.id === id);
      if(!it || !it.photo_id){ btn.disabled = false; return; }
      const done = await callApi('photo_delete', { id: it.photo_id });
      if(!done){ btn.disabled = false; return; }
      // zdjęcie zniknęło, więc zgłoszenie zamykamy jako obsłużone
      const d = await callApi('photo_reports_handle', { ids:[id], status:'resolved' });
      if(d) toast('Zdjęcie usunięte.');
      adoptPhotos(d);
      return;
    }

    const d = await callApi('photo_reports_handle', { ids:[id], status:'dismissed' });
    if(d) toast('Zgłoszenie odrzucone.');
    adoptPhotos(d);
  });

  // Przełączanie zakładek: Komentarze / Zdjęcia.
  function showReportsTab(name){
    document.querySelectorAll('#reportsBackdrop .notify-tab').forEach(b => {
      const on = b.dataset.rtab === name;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('#reportsBackdrop .reports-pane').forEach(p => {
      p.hidden = p.dataset.rpane !== name;
    });
  }
  document.querySelectorAll('#reportsBackdrop .notify-tab').forEach(b => {
    b.addEventListener('click', () => showReportsTab(b.dataset.rtab));
  });

  function openReports(){
    el('reportsBackdrop').classList.add('open');
    callApi('comment_reports_list').then(adopt);
    callApi('photo_reports_list').then(adoptPhotos);
  }
  function closeReports(){ el('reportsBackdrop').classList.remove('open'); }
  el('reportsBtn').addEventListener('click', openReports);
  el('reportsClose').addEventListener('click', closeReports);
  el('reportsBackdrop').addEventListener('click', e => {
    if(e.target === el('reportsBackdrop')) closeReports();
  });

  // Licznik na pasku — komentarze razem ze zgłoszeniami zdjęć.
  callApi('comment_reports_list').then(adopt);
  callApi('photo_reports_list').then(adoptPhotos);
})();
</script>

<script>
/* ====================================================================
   Użytkownicy — punkty, rangi, bany (sekcja „Użytkownicy” w panelu)
   ==================================================================== */
(() => {
  const PAPI = 'admin-api.php';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const el = id => document.getElementById(id);

  const state = {
    q: '', rank: '', status: '', sort: 'points', minPoints: '',
    offset: 0, total: 0, busy: false,
    items: [], byId: new Map(),
    ranks: [], totalUsers: 0, banned: 0, manual: 0, active: 0
  };
  let openId = null;

  const fmtDate = t => {
    if(!t) return '—';
    try{ return new Date(t).toLocaleString('pl-PL', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }); }
    catch(_){ return String(t); }
  };

  async function call(action, payload, isGet){
    const csrf = window.zmGetCsrf ? window.zmGetCsrf() : '';
    try{
      const res = isGet
        ? await fetch(`${PAPI}?action=${encodeURIComponent(action)}&${new URLSearchParams(payload || {}).toString()}`, { credentials:'include' })
        : await fetch(PAPI, {
            method:'POST', credentials:'include',
            headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify(Object.assign({ action, csrf }, payload || {}))
          });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const d = await res.json();
      if(d && d.error){ toast('Użytkownicy: ' + (d.message || d.error)); return null; }
      return d;
    }catch(_){ toast('Użytkownicy: błąd połączenia'); return null; }
  }

  function params(offset){
    const p = {
      action:'users_list', per_page: 50, offset,
      q: state.q, rank: state.rank, status: state.status, sort: state.sort
    };
    if(state.minPoints !== '') p.min_points = state.minPoints;
    return p;
  }

  function rankStyle(pos){
    const hue = (pos * 47 + 8) % 360;
    return `background:hsl(${hue} 45% 30%);color:hsl(${hue} 70% 82%)`;
  }

  function rankChip(r){
    if(!r || !r.rank_name) return '';
    const std = `<span class="usr-rank-chip" style="${rankStyle(posOf(r.rank_id))}" title="${r.rank_manual ? 'Ranga ustawiona ręcznie przez admina' : 'Ranga z punktów'}">${esc(r.rank_name)}${r.rank_manual ? ' ✎' : ''}</span>`;
    let extra = '';
    if(r.rank_special && r.rank_special_name){
      extra = `<span class="usr-rank-chip" style="${rankStyle(posOf(r.rank_special_id))}" title="Ranga specjalna — nadaje ją wyłącznie administracja">★ ${esc(r.rank_special_name)}</span>`;
    }
    return std + extra;
  }

  function posOf(rankId){
    const def = state.ranks.find(x => x.id === Number(rankId));
    return def ? def.position : 0;
  }

  function render(){
    const list = el('usersList');
    if(state.offset === 0 && !state.items.length){
      list.innerHTML = '<div class="admin-empty">Brak użytkowników spełniających kryteria.</div>';
      el('usrMoreBtn').style.display = 'none';
      el('usrCount').textContent = 0;
      return;
    }
    state.items.forEach(u => {
      state.byId.set(u.id, u);
      const banned = u.banned ? '<span class="usr-ban-chip" title="Konto zbanowane">zbanowany</span>' : '';
      const avatar = u.avatar
        ? `<img class="usr-avatar-photo" src="${esc(u.avatar)}" alt="" loading="lazy" decoding="async">`
        : `<span class="usr-avatar">${esc((u.username || '?')[0].toUpperCase())}</span>`;
      list.insertAdjacentHTML('beforeend', `
      <div class="admin-item user-admin-item ${u.banned ? 'unpublished' : ''}" data-uid="${u.id}">
        <span></span>
        <div class="usr-main">
          ${avatar}
          <div class="usr-info">
            <div class="usr-name">${esc(u.display_name || u.username)} <span class="usr-atsign">@${esc(u.username)}</span> ${banned}</div>
            <div class="admin-text">${esc(u.email || 'brak e-maila')} · dołączył: ${fmtDate(u.created_at)}</div>
          </div>
        </div>
        <div class="admin-item-body">
          <div class="admin-stats">
            ${rankChip(u)}
            <span class="admin-chip" title="Liczba punktów">${u.points} pkt</span>
            <span class="admin-chip" title="Zatwierdzone opublikowane zdjęcia">📷 ${u.photos}</span>
            <span class="admin-chip" title="Komentarze">💬 ${u.comments}</span>
            <span class="admin-chip" title="Polubienia zdjęć">♥ ${u.likes}</span>
          </div>
        </div>
        <div class="admin-item-actions">
          <button type="button" class="admin-edit-btn" data-user-open="${u.id}">Szczegóły</button>
        </div>
      </div>`);
    });
    el('usrCount').textContent = state.total;
    el('usrMoreBtn').style.display = state.offset < state.total ? 'block' : 'none';
  }

  async function refresh(){
    if(state.busy) return;
    state.busy = true;
    state.offset = 0;
    state.items = [];
    state.byId.clear();
    el('usersList').innerHTML = '';
    const btn = el('usrMoreBtn');
    btn.disabled = true;
    try{
      const d = await call('users_list', params(0), true);
      if(!d) return;
      state.total = d.total || 0;
      adoptRanks(d.ranks);
      state.items = d.items || [];
      state.offset = state.items.length;
      render();
    }catch(_){
      el('usersList').innerHTML = '<div class="admin-error">Nie udało się wczytać listy użytkowników.</div>';
    } finally {
      state.busy = false;
      btn.disabled = false;
    }
  }

  async function loadMore(){
    if(state.busy || state.offset >= state.total) return;
    state.busy = true;
    const btn = el('usrMoreBtn');
    btn.disabled = true;
    try{
      const d = await call('users_list', params(state.offset), true);
      if(!d) return;
      state.items = state.items.concat(d.items || []);
      state.offset = state.items.length;
      render();
    }finally{
      state.busy = false;
      btn.disabled = false;
    }
  }

  function adoptRanks(ranks){
    if(!ranks) return;
    state.ranks = ranks.ranks || [];
    state.totalUsers = ranks.total_users || 0;
    state.manual = ranks.manual_overrides || 0;
    state.banned = ranks.banned_users || 0;
    state.active = Math.max(0, state.totalUsers - state.banned);
    const sel = el('usrRank');
    const cur = sel.value;
    sel.innerHTML = '<option value="">Wszystkie rangi</option>' + (ranks.ranks || []).map(r =>
      `<option value="${r.id}">${r.special ? '★ ' : ''}${esc(r.name)} (${r.min_points}+)</option>`).join('');
    sel.value = state.ranks.some(r => String(r.id) === cur) ? cur : '';
    el('usrTotal').textContent = state.totalUsers;
    el('usrManual').textContent = state.manual;
    el('usrBanned').textContent = state.banned;
    el('usrActive').textContent = state.active;
    fillRankSelect(el('umRankSel'), 0);
    fillSpecialRankSelect(el('umSpSel'), 0);
  }

  function fillRankSelect(sel, selected){
    sel.innerHTML = '<option value="0">Automatyczna (wróć do punktów)</option>' + (state.ranks || []).filter(r => !r.special).map(r =>
      `<option value="${r.id}">${esc(r.name)} (od ${r.min_points} pkt)</option>`).join('');
    if(selected) sel.value = String(selected);
  }

  function fillSpecialRankSelect(sel, selected){
    sel.innerHTML = '<option value="0">Brak rangi specjalnej</option>' + (state.ranks || []).filter(r => r.special).map(r =>
      `<option value="${r.id}">★ ${esc(r.name)}</option>`).join('');
    if(selected) sel.value = String(selected);
  }

  /* ------------------------------------------------ szczegóły użytkownika */

  function setBanBtn(banned){
    const b = el('umBanBtn');
    b.textContent = banned ? 'Odblokuj konto' : 'Zbanuj konto';
    b.classList.toggle('danger', !banned);
    el('umBannedInfo').textContent = banned
      ? 'Konto zbanowane — użytkownik nie może się zalogować ani pisać komentarzy/polubień.'
      : 'Ban blokuje logowanie oraz komentarze i polubienia.';
  }

  async function openUser(id){
    openId = id;
    el('usrModalBackdrop').style.display = 'flex';
    el('umHistory').innerHTML = '<p class="muted">Ładowanie…</p>';
    const d = await call('user_detail', { id }, true);
    if(!d || !d.user){ closeUser(); return; }
    const u = d.user;
    el('usrModalName').textContent = u.display_name || u.username;
    el('usrModalLogin').textContent = '@' + u.username;
    const rank = state.ranks.find(r => r.id === u.rank_id);
    const chip = el('usrModalRank');
    chip.style.display = u.rank_name ? '' : 'none';
    chip.textContent = u.rank_name;
    if(rank){ chip.style.cssText = rankStyle(rank.position); }
    chip.classList.toggle('usr-rank-chip', true);
    const sp = state.ranks.find(r => r.id === u.rank_special_id);
    const spChip = el('usrModalSpRank');
    spChip.style.display = u.rank_special_name ? '' : 'none';
    spChip.textContent = u.rank_special_name ? '★ ' + u.rank_special_name : '';
    if(sp){ spChip.style.cssText = rankStyle(sp.position); }
    spChip.classList.toggle('usr-rank-chip', true);
    el('usrModalBanned').style.display = u.banned ? '' : 'none';
    el('umPhotos').textContent = u.counters.photos;
    el('umComments').textContent = u.counters.comments;
    el('umLikes').textContent = u.counters.likes;
    el('umPoints').textContent = u.points;
    el('umJoined').textContent = u.created_at ? u.created_at.slice(0, 10) : '—';
    el('umUsername').value = u.username;
    el('umEmail').value = u.email || '';
    el('umPassword').value = '';
    el('umDelta').value = '';
    el('umNote').value = '';
    fillRankSelect(el('umRankSel'), u.rank_manual ? u.rank_id : 0);
    fillSpecialRankSelect(el('umSpSel'), u.rank_special_id || 0);
    setBanBtn(u.banned);
    renderHistory(d.history || []);
    const currentAdminUsername = (window.zmAdminUser || '');
    const isSuper = currentAdminUsername === 'admin';
    const isSelf = !!currentAdminUsername && u.username === currentAdminUsername;
    const isTargetAdmin = u.role === 'admin';
    const locked = isSelf || (isTargetAdmin && !isSuper);
    el('umRoleBtn').style.display = (isSuper && !isSelf) ? '' : 'none';
    el('umRoleBtn').textContent = isTargetAdmin ? 'Odbierz prawa administratora' : 'Nadaj prawa administratora';
    el('umRoleBtn').dataset.role = isTargetAdmin ? 'user' : 'admin';
    el('umBanBtn').style.display = locked ? 'none' : '';
    el('umDeleteBtn').style.display = locked ? 'none' : '';
  }

  function closeUser(){ openId = null; el('usrModalBackdrop').style.display = 'none'; }

  const ACTION_LABEL = {
    photo_add: 'zdjęcie zatwierdzone',
    comment: 'komentarz',
    photo_like: 'polubienie zdjęcia',
    attraction_bonus: 'bonus za atrakcję',
    admin_manual: 'korekta administracyjna'
  };

  function renderHistory(history){
    const box = el('umHistory');
    if(!history.length){
      box.innerHTML = '<p class="muted">Brak wpisów w historii punktów.</p>';
      return;
    }
    box.innerHTML = history.map(h => `
      <div class="ph-row">
        <span class="ph-date">${fmtDate(h.created_at)}</span>
        <span class="ph-delta ${h.delta >= 0 ? 'ph-plus' : 'ph-minus'}">${h.delta > 0 ? '+' : ''}${h.delta}</span>
        <span class="ph-text">${esc(ACTION_LABEL[h.action] || h.action)}${h.ref_type || h.ref_id ? ` (${esc(h.ref_type || '')}${h.ref_id ? ' #' + h.ref_id : ''})` : ''}${h.note ? ` — ${esc(h.note)}` : ''}</span>
        <span class="ph-after">= <b>${h.points_after}</b> pkt</span>
      </div>`).join('');
  }

  /* -------------------------------------------------- akcje (POST) */

  /* Zmiana loginu albo usunięcie konta poprawia autorów w komentarzach, więc
     lista komentarzy musi się odświeżyć. Zmiana rangi czy punktów nie rusza
     nazw, więc wystarczy lista użytkowników. */
  function afterUserChange(identityChanged){
    if(identityChanged && window.zmComments) window.zmComments.refresh();
  }

  async function saveUser(){
    const username = el('umUsername').value.trim();
    const email = el('umEmail').value.trim();
    const password = el('umPassword').value;
    if(!username){ toast('Podaj login.'); return; }
    if(password && password.length < 8){ toast('Hasło musi mieć min. 8 znaków.'); return; }
    const d = await call('user_edit', { id: openId, username, email, password });
    if(d && d.ok){
      toast('Zapisano dane konta.');
      el('usrModalName').textContent = username;
      el('usrModalLogin').textContent = '@' + username;
      refresh();
      // stary login wisi jeszcze w nagłówkach komentarzy
      const prev = state.byId.get(openId);
      if(prev && prev.username !== username) afterUserChange(true);
    }
  }

  /* -------------------------------------------------- nowe konto */

  function openAddUser(){
    el('usrAddForm').reset();
    el('usrAddBackdrop').style.display = 'flex';
    setTimeout(() => el('uaUsername').focus(), 0);
  }

  function closeAddUser(){
    el('usrAddBackdrop').style.display = 'none';
  }

  async function createUser(){
    const username = el('uaUsername').value.trim();
    const displayName = el('uaDisplay').value.trim();
    const email = el('uaEmail').value.trim();
    const password = el('uaPassword').value;

    if(!/^[A-Za-z0-9_.-]{3,40}$/.test(username)){
      toast('Login musi mieć 3–40 znaków: litery, cyfry, _ . -');
      el('uaUsername').focus();
      return;
    }
    if(!password || password.length < 8){
      toast('Hasło musi mieć min. 8 znaków.');
      el('uaPassword').focus();
      return;
    }

    const save = el('usrAddSave');
    save.disabled = true;
    try{
      const d = await call('user_create', {
        username,
        display_name: displayName,
        email,
        password
      });
      if(d && d.ok){
        toast('Utworzono konto VIP: ' + d.user.username);
        closeAddUser();
        refresh();
      }
    }finally{
      save.disabled = false;
    }
  }

  async function applyPoints(){
    const delta = parseInt(el('umDelta').value, 10);
    if(!Number.isFinite(delta) || delta === 0){ toast('Podaj wartość różną od 0.'); return; }
    const d = await call('user_points', { id: openId, delta, note: el('umNote').value });
    if(d && d.ok){
      toast(`Zmieniono punkty o ${d.delta}.`);
      el('umPoints').textContent = d.points;
      const u = state.byId.get(openId);
      if(u) u.points = d.points;
      openUser(openId);
      refresh();
    }
  }

  async function setRank(){
    const rankId = parseInt(el('umRankSel').value, 10);
    const d = await call('user_rank', { id: openId, rank_id: rankId });
    if(d && d.ok){
      toast(d.rank_manual ? `Ustawiono rangę: ${d.rank_name}` : 'Przywrócono rangę automatyczną.');
      el('usrModalBanned').style.display = 'none';
      refresh();
      openUser(openId);
    }
  }

  async function setSpecialRank(){
    const specialRankId = parseInt(el('umSpSel').value, 10);
    const d = await call('user_rank', { id: openId, special_rank_id: specialRankId });
    if(d && d.ok){
      toast(d.rank_special_name ? `Ustawiono rangę specjalną: ${d.rank_special_name}` : 'Zdjęto rangę specjalną.');
      refresh();
      openUser(openId);
    }
  }

  async function toggleRole(){
    const role = el('umRoleBtn').dataset.role === 'admin' ? 'admin' : 'user';
    const d = await call('user_role', { id: openId, role });
    if(d && d.ok){
      toast(d.role === 'admin' ? 'Przyznano prawa administratora.' : 'Odebrano prawa administratora.');
      refresh();
      openUser(openId);
    }
  }

  async function toggleBan(){
    const u = state.byId.get(openId);
    const d = await call('user_ban', { id: openId, banned: !(u && u.banned) });
    if(d && d.ok){
      toast(d.banned ? 'Konto zbanowane.' : 'Konto odblokowane.');
      setBanBtn(d.banned);
      refresh();
      openUser(openId);
    }
  }

  async function deleteUser(){
    if(!confirm('Usunąć konto „' + el('usrModalName').textContent + '”?\n'
      + 'Zdjęcia zostają (stają się anonimowe), komentarze również. '
      + 'Usuwają się: punkty i ich historia, polubienia, ulubione i awatar. Tej operacji nie da się cofnąć.')) return;
    const d = await call('user_delete', { id: openId });
    if(d && d.ok){
      toast('Konto zostało usunięte.');
      closeUser();
      refresh();
      // autorzy w komentarzach zmieniają się na „Anonim”, a nagłówki statystyk na górze nie
      afterUserChange(true);
    }
  }

  /* -------------------------------------------------- edytor rang */

  let rankEditorRows = [];
  let rankBusy = false;

  async function openRanks(){
    el('usrRanksBackdrop').style.display = 'flex';
    el('ranksEditor').innerHTML = '<p class="muted">Ładowanie…</p>';
    const d = await call('rank_list', null, true);
    rankEditorRows = (d && d.ranks) || [];
    renderRankEditor();
  }
  function closeRanks(){ el('usrRanksBackdrop').style.display = 'none'; rankEditorRows = []; }

  function renderRankEditor(){
    const box = el('ranksEditor');
    if(!rankEditorRows.length){
      box.innerHTML = '<p class="muted">Brak rang. Dodaj przynajmniej jedną (od 0 pkt).</p>';
      return;
    }
    box.innerHTML = rankEditorRows.map((r, i) => `
      <div class="rr-row" data-i="${i}">
        <input type="text" class="rr-name" value="${esc(r.name)}" placeholder="Nazwa rangi" maxlength="60">
        <div class="rr-minwrap"><label>od</label><input type="number" class="rr-min" min="0" step="1" value="${r.special ? '' : (r.min_points ?? 0)}" placeholder="—" ${r.special ? 'disabled' : ''}></div>
        <label class="rr-special" title="Ranga specjalna (np. dla drużyny) — tylko z ręcznego nadania przez admina, nie zdobędziesz jej punktami">
          <input type="checkbox" class="rr-sp" ${r.special ? 'checked' : ''}> <span class="sp-star">★</span> spec.
        </label>
        <span class="rr-users" title="Użytkownicy z tą rangą">${r.users ?? r.auto_users ?? 0} użyt.</span>
        ${rankEditorRows.length > 1 ? `<button type="button" class="admin-del-btn" data-rr-del="${i}" title="Usuń rangę">Usuń</button>` : ''}
      </div>`).join('');
    el('usrRanksSave').disabled = false;
    box.insertAdjacentHTML('beforeend', '<button type="button" class="tool-btn" id="rrAddBtn">+ Dodaj rangę</button>');
    const addBtn = el('rrAddBtn');
    if(addBtn){
      addBtn.addEventListener('click', () => {
        const lastMin = rankEditorRows.length ? (rankEditorRows[rankEditorRows.length - 1].min_points ?? 0) : 0;
        rankEditorRows.push({ id: 0, position: rankEditorRows.length + 1, name: '', min_points: lastMin + 50, special: false });
        renderRankEditor();
      });
    }
    box.querySelectorAll('.rr-sp').forEach(cb => {
      cb.addEventListener('change', () => {
        const row = cb.closest('.rr-row');
        const idx = Number(row.dataset.i);
        if (idx >= 0 && rankEditorRows[idx]) {
          rankEditorRows[idx].special = cb.checked;
        }
        const minInput = row.querySelector('.rr-min');
        if (minInput) {
          minInput.disabled = cb.checked;
          if (cb.checked) minInput.value = '';
        }
      });
    });
  }

  function collectRanks(){
    const rows = [...el('ranksEditor').querySelectorAll('.rr-row')];
    return rows.map((r, i) => {
      const name = r.querySelector('.rr-name').value;
      const min = parseInt(r.querySelector('.rr-min').value, 10);
      const special = r.querySelector('.rr-sp').checked;
      return {
        id: rankEditorRows[i] ? rankEditorRows[i].id : 0,
        position: i + 1,
        name,
        min_points: special ? 0 : (Number.isFinite(min) ? min : 0),
        special,
      };
    });
  }

  async function saveRanks(){
    const rows = collectRanks();
    if(rows.length < 1){ toast('Dodaj przynajmniej jedną rangę.'); return; }
    // rangi specjalne nie tworzą progresji punktowej — sprawdzamy tylko zwykłe
    const norms = rows.filter(r => !r.special);
    if(norms.length && norms[0].min_points !== 0){ toast('Najniższa ranga musi zaczynać się od 0 punktów.'); return; }
    for(let i = 1; i < norms.length; i++){
      if(!(norms[i].min_points > norms[i - 1].min_points)){ toast('Progi rang muszą rosnąć.'); return; }
    }
    if(rows.some(r => !r.name.trim())){ toast('Każda ranga musi mieć nazwę.'); return; }
    el('usrRanksSave').disabled = true;
    const d = await call('rank_save', { ranks: rows });
    if(d && d.ok){
      toast('Zapisano rangi.');
      adoptRanks(d);
      refresh();
      closeRanks();
    } else {
      el('usrRanksSave').disabled = false;
    }
  }

  /* -------------------------------------------------- wiązania */

  el('tabUsers').addEventListener('click', () => { setTimeout(() => el('usrSearch').focus(), 0); });

  let searchTimer = null;
  el('usrSearch').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.q = el('usrSearch').value.trim(); refresh(); }, 250);
  });
  ['usrRank','usrStatus','usrSort'].forEach(id => el(id).addEventListener('change', () => {
    state.rank = el('usrRank').value;
    state.status = el('usrStatus').value;
    state.sort = el('usrSort').value;
    refresh();
  }));
  el('usrMinPoints').addEventListener('change', () => {
    state.minPoints = el('usrMinPoints').value;
    refresh();
  });
  el('usrResetBtn').addEventListener('click', () => {
    el('usrSearch').value = '';
    el('usrStatus').value = '';
    el('usrMinPoints').value = '';
    el('usrSort').value = 'points';
    state.q = ''; state.rank = ''; state.status = ''; state.minPoints = ''; state.sort = 'points';
    refresh();
  });
  el('usrMoreBtn').addEventListener('click', loadMore);

  el('usersList').addEventListener('click', e => {
    const b = e.target.closest('[data-user-open]');
    if(b) openUser(+b.dataset.userOpen);
  });

  document.querySelectorAll('[data-uf]').forEach(card => card.addEventListener('click', () => {
    const f = card.dataset.uf;
    if(f === 'manual'){ el('usrRank').value = ''; state.rank = ''; }
    el('usrStatus').value = f === 'banned' ? 'banned' : (f === 'active' ? 'active' : '');
    state.status = f === 'banned' ? 'banned' : (f === 'active' ? 'active' : '');
    refresh();
  }));

  el('usrModalClose').addEventListener('click', closeUser);
  el('usrModalBackdrop').addEventListener('click', e => { if(e.target === el('usrModalBackdrop')) closeUser(); });
  el('usrEditForm').addEventListener('submit', e => { e.preventDefault(); saveUser(); });
  el('usrAddBtn').addEventListener('click', openAddUser);
  el('usrAddClose').addEventListener('click', closeAddUser);
  el('usrAddCancel').addEventListener('click', closeAddUser);
  el('usrAddBackdrop').addEventListener('click', e => { if(e.target === el('usrAddBackdrop')) closeAddUser(); });
  el('usrAddForm').addEventListener('submit', e => { e.preventDefault(); createUser(); });
  el('umPointsBtn').addEventListener('click', applyPoints);
  el('umRankBtn').addEventListener('click', setRank);
  el('umSpBtn').addEventListener('click', setSpecialRank);
  el('umRoleBtn').addEventListener('click', toggleRole);
  el('umBanBtn').addEventListener('click', toggleBan);
  el('umDeleteBtn').addEventListener('click', deleteUser);

  el('usrRanksBtn').addEventListener('click', openRanks);
  el('usrRanksClose').addEventListener('click', closeRanks);
  el('usrRanksCancel').addEventListener('click', closeRanks);
  el('usrRanksBackdrop').addEventListener('click', e => { if(e.target === el('usrRanksBackdrop')) closeRanks(); });
  el('usrRanksSave').addEventListener('click', saveRanks);
  el('ranksEditor').addEventListener('click', e => {
    const del = e.target.closest('[data-rr-del]');
    if(del){
      const i = +del.dataset.rrDel;
      rankEditorRows.splice(i, 1);
      rankEditorRows.forEach((r, j) => r.position = j + 1);
      renderRankEditor();
    }
  });

  document.addEventListener('keydown', e => {
    if(e.key === 'Escape'){
      if(el('usrModalBackdrop').style.display === 'flex') closeUser();
      else if(el('usrAddBackdrop').style.display === 'flex') closeAddUser();
      else if(el('usrRanksBackdrop').style.display === 'flex') closeRanks();
    }
  });

  window.zmUsers = { refresh, afterUserChange };
})();
</script>

<script>
/* ------------------------------------------------------------------
   Statystyki — zakładka „Statystyki" w panelu (6 pod-zakładek):
   Przegląd, Ruch i odwiedzający, Zdjęcia i miejscowości, Rozwój
   społeczności, SEO i źródła, Stan techniczny. Dane z licznika
   traffic_events i bazy treści; szczegółowe IP widoczne adminowi przez 30 dni,
   bez cookies i bez pełnego nagłówka User-Agent. Eksport CSV i raport
   do wydruku/PDF obsługują osobne pliki administracyjne
   (traffic-export.php, traffic-print.php).
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const num = n => Number(n || 0).toLocaleString('pl-PL');
  const num2 = n => Number(n || 0).toLocaleString('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2});

  let range = '30d';
  let activeTab = (() => {
    try{
      const saved = sessionStorage.getItem('zm-admin-stats-tab');
      return ['overview','traffic','photos','community','seo','gsc','tech'].includes(saved) ? saved : 'overview';
    }catch(_){ return 'overview'; }
  })();
  let data = null;
  let gscRange = '28d';
  let photoSort = { key: 'views', dir: -1 };

  function delta(v){
    if(v === null || v === undefined || v === '') return '';
    const sign = v > 0 ? '+' : '';
    const cls = v > 0 ? 'up' : (v < 0 ? 'down' : '');
    return `<span class="${cls}">${sign}${Number(v).toLocaleString('pl-PL', {minimumFractionDigits:1, maximumFractionDigits:1})}%</span> vs poprzedni okres`;
  }

  function empty(msg){ return `<div class="st-empty">${esc(msg || 'Brak danych.')}</div>`; }
  function card(title, body){ return `<div class="tr-card"><h3>${esc(title)}</h3>${body}</div>`; }
  function grid2(cards){ return `<div class="st-grid2">${cards.join('')}</div>`; }

  function kpi(label, value, sub){
    return `<div class="stat-card static"><div class="label">${esc(label)}</div>` +
      `<div class="value">${value}</div>` +
      (sub ? `<div class="st-kpi-sub">${sub}</div>` : '') + '</div>';
  }

  // „label + pasek + liczba" — wspólna dla list rankingowych.
  function shareTable(items, opts){
    opts = opts || {};
    if(!items || !items.length) return empty('Brak danych.');
    const max = Math.max(1, ...items.map(it => Number(it.value || 0)));
    const unit = opts.unit || 'Odsłony';
    const bar = opts.bar || 'var(--teal, #5a8a86)';
    return `<table class="tr-table"><thead><tr><th>Pozycja</th><th style="width:34%"></th><th class="num">${esc(unit)}</th></tr></thead><tbody>` +
      items.map(it => {
        const w = Math.max(2, Math.round(100 * Number(it.value || 0) / max));
        const label = it.link
          ? `<a href="${esc(it.link)}" target="_blank" rel="noopener" style="color:inherit;text-decoration:none">${esc(it.label)}</a>`
          : esc(it.label);
        return `<tr><td>${label}${it.tag ? ` <span class="st-pill">${esc(it.tag)}</span>` : ''}</td>` +
          `<td><span class="tr-bar" style="width:${w}%; background:${bar}"></span></td>` +
          `<td class="num">${num(it.value)}</td></tr>`;
      }).join('') + '</tbody></table>';
  }

  function table(head, rows){
    if(!rows || !rows.length) return empty('Brak danych.');
    const th = head.map(h => `<th class="${h.num ? 'num' : ''}">${h.label}</th>`).join('');
    const body = rows.map(r => '<tr>' + r.map((c, i) =>
      `<td class="${head[i] && head[i].num ? 'num' : ''}">${c}</td>`).join('') + '</tr>').join('');
    return `<div class="tr-table-wrap"><table class="tr-table"><thead><tr>${th}</tr></thead><tbody>${body}</tbody></table></div>`;
  }

  // Wykres słupkowy w stylu reszty panelu. Pierwsza seria to słupek bazowy,
  // kolejne nakładają się na niego (jak „unikalni" na „odsłony" w liczniku).
  function barChart(series, keys){
    if(!series || !series.length) return empty('Brak danych.');
    let max = 1;
    series.forEach(s => keys.forEach(k => { max = Math.max(max, Number(s[k.key] || 0)); }));
    const bars = series.map(s => {
      const span = (s.days && s.days > 1) ? ` (${s.days} dni)` : '';
      const tip = keys.map(k => `${k.label}: ${num(s[k.key] || 0)}`).join(', ') + ' — ' + s.day + span;
      const baseH = Math.max(1, Math.round(150 * Number(s[keys[0].key] || 0) / max));
      let inner = '';
      for(let i = 1; i < keys.length; i++){
        const v = Number(s[keys[i].key] || 0);
        if(v > 0) inner += `<span class="uv" style="height:${Math.max(1, Math.round(150 * v / max))}px;background:${keys[i].color}"></span>`;
      }
      return `<div class="bar" style="background:${keys[0].color};height:${baseH}px" title="${esc(tip)}">${inner}</div>`;
    }).join('');
    const legend = keys.map(k => `<span><i style="background:${k.color}"></i>${esc(k.label)}</span>`).join('');
    return `<div class="tr-chart">${bars}</div>` +
      `<div class="tr-chart-axis"><span>${esc(series[0].day)}</span><span>${esc(series[series.length - 1].day)}</span></div>` +
      `<div class="tr-legend">${legend}</div>`;
  }

  function hoursList(hours){
    if(!hours || !hours.length) return empty('Brak danych.');
    const max = Math.max(1, ...hours.map(h => h.pageviews));
    return hours.map(h => {
      const w = Math.round(100 * h.pageviews / max);
      const hr = String(h.hour).padStart(2, '0');
      return `<div class="tr-hour"><span class="m">${hr}:00</span><span class="b"><span style="width:${w}%"></span></span><span class="n">${num(h.pageviews)}</span></div>`;
    }).join('');
  }

  function bytes(b){
    b = Number(b || 0);
    if(b < 1024) return b + ' B';
    if(b < 1048576) return num2(b / 1024) + ' KB';
    if(b < 1073741824) return num2(b / 1048576) + ' MB';
    return num2(b / 1073741824) + ' GB';
  }

  /* ------------------------------ PRZEGLĄD ------------------------------ */
  function renderOverview(d){
    const o = d.overview || {};
    const t = d.traffic || {};
    const kpis = `<div class="stats-row">` +
      kpi('Odsłony', num(o.pageviews), delta(o.pageviews_delta)) +
      kpi('Unikalni (dziennie)', num(o.visitors), delta(o.visitors_delta)) +
      kpi('Odsłony / dzień', num2(o.per_day)) +
      kpi('Odsłony / odwiedzający', num2(o.per_visitor)) +
      kpi('Nowe konta', num(o.new_users)) +
      kpi('Nowe zdjęcia', num(o.new_photos)) +
      kpi('Komentarze', num(o.new_comments)) +
      kpi('Polubienia', num(o.new_likes)) +
      kpi('Aktywni użytkownicy', num(o.active_users)) +
      `</div>`;

    const chart = card('Ruch w czasie (odsłony i unikalni)', barChart(t.series || [], [
      { key: 'pageviews', label: 'Odsłony', color: 'var(--teal, #5a8a86)' },
      { key: 'visitors', label: 'Unikalni', color: 'var(--gold)' },
    ]));

    const top = grid2([
      card('Miejscowość wiodącego zdjęcia', o.top_place ? `<div class="value" style="font-family:'Fraunces',serif;font-size:20px">${esc(o.top_place)}</div>` : empty('Brak danych.')),
      card('Najpopularniejsze zdjęcie', o.top_photo
        ? `<div style="font-size:15px">${esc(o.top_photo.title)}</div><div class="st-kpi-sub">${num(o.top_photo.views)} odsłon — <a href="${esc(o.top_photo.link)}" target="_blank" rel="noopener" style="color:var(--gold)">otwórz</a></div>`
        : empty('Brak danych.')),
    ]);

    const note = `<div class="tr-card"><div class="tr-muted">${esc((d.notes && d.notes.privacy) || '')}</div></div>`;

    el('stPanel-overview').innerHTML = kpis + chart + `<div style="height:14px"></div>` + top + note;
  }

  /* -------------------------- RUCH I ODWIEDZAJĄCY ----------------------- */
  function renderTraffic(d){
    const t = d.traffic || {};
    const tot = t.totals || {};
    const prevPv = (tot.pageviews_prev === undefined || tot.pageviews_prev === null) ? '' : ' · poprzednio ' + num(tot.pageviews_prev);
    const prevUv = (tot.visitors_prev === undefined || tot.visitors_prev === null) ? '' : ' · poprzednio ' + num(tot.visitors_prev);
    const kpis = `<div class="stats-row">` +
      kpi('Odsłony', num(tot.pageviews), delta(tot.pageviews_delta) + prevPv) +
      kpi('Unikalni (dziennie)', num(tot.visitors), delta(tot.visitors_delta) + prevUv) +
      kpi('Odsłony / dzień', num2(tot.per_day)) +
      kpi('Odsłony / odwiedzający', num2(tot.per_visitor)) +
      kpi('Boty (poza metrykami)', num(tot.bots)) +
      `</div>`;

    const periodRows = [
      ['Dzisiaj', (t.period_summary || {}).today],
      ['Ostatnie 7 dni', (t.period_summary || {}).week],
      ['Bieżący miesiąc', (t.period_summary || {}).month],
    ].filter(x => x[1]).map(([label, p]) => [
      esc(label), num(p.pageviews), num(p.visitor_days), num((p.events || {}).comments),
      num((p.events || {}).photo_likes), num((p.events || {}).comment_likes), num((p.events || {}).favorites),
    ]);
    const periods = card('Podsumowanie okresów i zdarzeń', table(
      [{label:'Okres'}, {label:'Odsłony',num:true}, {label:'Unikalni / dzień',num:true},
       {label:'Komentarze',num:true}, {label:'Like zdjęć',num:true}, {label:'Like komentarzy',num:true}, {label:'Ulubione',num:true}],
      periodRows
    ) + `<div class="st-kpi-sub" style="margin-top:8px">${esc(t.periods_note || '')}</div>`);

    const chart = card('Ruch w czasie', barChart(t.series || [], [
      { key: 'pageviews', label: 'Odsłony', color: 'var(--teal, #5a8a86)' },
      { key: 'visitors', label: 'Unikalni', color: 'var(--gold)' },
    ]));

    const pages = card('Typy stron', shareTable((t.pages || []).map(x => ({ label: x.label, value: x.pageviews, tag: num(x.visitors) + ' unik.' }))));
    const refClasses = card('Źródła ruchu (klasy)', shareTable((t.ref_classes || []).map(x => ({ label: x.label, value: x.pageviews }))));
    const hosts = card('Źródła ruchu — hosty zewnętrzne', table(
      [{ label: 'Źródło' }, { label: 'Odsłony', num: true }],
      (t.referrers || []).map(r => [esc(r.classLabel) + (r.host ? ' · ' + esc(r.host) : ''), num(r.pageviews)])
    ));
    const devices = card('Urządzenia', shareTable((t.devices || []).map(x => ({ label: x.label, value: x.pageviews, tag: num(x.visitors) + ' unik.' }))));
    const browsers = card('Przeglądarki', shareTable((t.browsers || []).map(x => ({ label: x.label, value: x.pageviews, tag: num(x.visitors) + ' unik.' })), { bar: 'var(--gold)' }));
    const systems = card('Systemy', shareTable((t.systems || []).map(x => ({ label: x.label, value: x.pageviews, tag: num(x.visitors) + ' unik.' })), { bar: 'var(--teal, #5a8a86)' }));
    const browserVersions = card('Wersje przeglądarek', shareTable((t.browser_versions || []).map(x => ({label:x.label, value:x.pageviews})), {unit:'Odsłony', bar:'var(--gold)'}));
    const osVersions = card('Wersje systemów', shareTable((t.os_versions || []).map(x => ({label:x.label, value:x.pageviews})), {unit:'Odsłony', bar:'var(--teal, #5a8a86)'}));
    const deviceModels = card('Modele urządzeń', shareTable((t.device_models || []).map(x => ({label:x.label, value:x.pageviews})), {unit:'Odsłony'}));
    const countries = card('Kraje · dane Cloudflare', shareTable((t.countries || []).map(x => ({label:x.label, value:x.pageviews})), {unit:'Odsłony', bar:'var(--gold)'}));
    const hours = card('Aktywność w ciągu doby', hoursList(t.hours || []));
    const weekday = card('Dni tygodnia', shareTable((t.weekday || []).map(x => ({ label: x.label, value: x.pageviews }))));
    const topUrls = card('Najczęściej odwiedzane adresy', table(
      [{ label: 'Adres' }, { label: 'Odsłony', num: true }, { label: 'Unikalni', num: true }],
      (t.top_urls || []).map(u => [`<span class="st-tag">${esc(u.path)}</span>`, num(u.pageviews), num(u.visitors)])
    ));
    const entry = card('Strony wejścia', table(
      [{ label: 'Adres' }, { label: 'Wejścia', num: true }, { label: 'Odwiedzający', num: true }],
      (t.entry_pages || []).map(u => [`<span class="st-tag">${esc(u.path)}</span>`, num(u.entries), num(u.visitors)])
    ) + `<div class="st-kpi-sub" style="margin-top:8px">${esc(t.entry_note || '')}</div>`);
    const topPhotos = card('Najpopularniejsze zdjęcia', table(
      [{ label: 'Tytuł' }, { label: 'Wyświetlenia', num: true }, { label: 'Polubienia', num: true }, { label: 'Ulubione', num: true }],
      (t.photos || []).map(p => [esc(p.title), num(p.views), num(p.likes), num(p.favorites)])
    ) + `<div class="st-kpi-sub" style="margin-top:8px">${esc(t.photos_note || '')}</div>`);

    const recentRows = (t.recent_visits || []).map(v => [
      esc(v.time || ''), esc(v.ip || '—'), esc(v.country || '—'),
      esc(v.path || v.page || ''),
      esc([v.device_model || '', v.device || ''].filter(Boolean).join(' · ')),
      esc([v.browser || '', v.browser_version || ''].filter(Boolean).join(' ')),
      esc([v.os || '', v.os_version || ''].filter(Boolean).join(' ')),
      esc(v.referrer || (v.ref_class === 'direct' ? 'Bezpośrednie' : v.ref_class || '')),
      v.is_bot ? '<span class="st-tag">robot</span>' : '<span class="st-tag">osoba</span>',
    ]);
    const recent = card('Ostatnie wejścia · maks. 100 · IP do 30 dni', table(
      [{label:'Data i godzina'}, {label:'Adres IP'}, {label:'Kraj'}, {label:'Strona'}, {label:'Urządzenie'},
       {label:'Przeglądarka'}, {label:'System'}, {label:'Źródło'}, {label:'Typ'}],
      recentRows
    ));

    el('stPanel-traffic').innerHTML = kpis + periods + chart + `<div style="height:14px"></div>` + grid2([pages, refClasses, hosts, devices, browsers, browserVersions, systems, osVersions, deviceModels, countries, hours, weekday, topUrls, entry, topPhotos]) + `<div style="height:14px"></div>` + recent;
  }

  /* ------------------------ ZDJĘCIA I MIEJSCOWOŚCI ---------------------- */
  function photoTable(items){
    if(!items || !items.length) return empty('Brak opublikowanych zdjęć.');
    const s = photoSort;
    const sorted = items.slice().sort((a, b) => {
      let x = a[s.key], y = b[s.key];
      if(s.key === 'like_ratio'){ x = x === null ? -1 : x; y = y === null ? -1 : y; }
      if(typeof x === 'string') return s.dir * x.localeCompare(y, 'pl');
      return s.dir * ((x || 0) - (y || 0));
    });
    const arrow = k => s.key === k ? (s.dir < 0 ? ' ▾' : ' ▴') : '';
    const head = `<tr>
      <th></th>
      <th class="st-sort" data-sort="title" style="cursor:pointer">Tytuł${arrow('title')}</th>
      <th>Miejsce</th>
      <th class="num st-sort" data-sort="views" style="cursor:pointer">Wyświetlenia${arrow('views')}</th>
      <th class="num st-sort" data-sort="likes" style="cursor:pointer">Polubienia${arrow('likes')}</th>
      <th class="num st-sort" data-sort="comments" style="cursor:pointer">Komentarze${arrow('comments')}</th>
      <th class="num st-sort" data-sort="favorites" style="cursor:pointer">Ulubione${arrow('favorites')}</th>
      <th class="num st-sort" data-sort="like_ratio" style="cursor:pointer">Polub./wyśw.${arrow('like_ratio')}</th>
    </tr>`;
    const rows = sorted.map(p => {
      const ratio = p.like_ratio === null ? '<span class="tr-muted">—</span>' : (p.like_ratio * 100).toLocaleString('pl-PL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
      const views = p.has_views ? num(p.views) : '<span class="tr-muted" title="Brak odsłon">0</span>';
      return `<tr>
        <td><a href="${esc(p.link)}" target="_blank" rel="noopener"><img class="st-thumb" src="${esc(p.thumb)}" alt="" loading="lazy"></a></td>
        <td><a href="${esc(p.link)}" target="_blank" rel="noopener" style="color:var(--gold);text-decoration:none">${esc(p.title)}</a></td>
        <td>${esc(p.place || '—')}</td>
        <td class="num">${views}</td>
        <td class="num">${num(p.likes)}</td>
        <td class="num">${num(p.comments)}</td>
        <td class="num">${num(p.favorites)}</td>
        <td class="num">${ratio}</td>
      </tr>`;
    }).join('');
    return `<table class="tr-table"><thead>${head}</thead><tbody>${rows}</tbody></table>`;
  }

  function renderPhotos(d){
    const p = d.photos || {};
    const pl = d.places || {};
    const kpis = `<div class="stats-row">` +
      kpi('Opublikowane zdjęcia', num(p.published_total)) +
      kpi('Suma odsłon', num(p.views_total), '+' + num(p.views_period) + ' w okresie') +
      kpi('Dodane w okresie', num(p.added_in_period)) +
      kpi('Miejscowości', num(pl.villages_total)) +
      kpi('Gminy', num((pl.gminas || []).length)) +
      `</div>`;

    const ranking = card('Ranking zdjęć (kliknij nagłówek, aby posortować)', photoTable(p.items || []));
    const villages = card('Miejscowości', shareTable((pl.villages || []).map(v => ({
      label: v.name, value: v.photos, tag: (v.gmina ? v.gmina + ' · ' : '') + num(v.views) + ' wyśw.', link: v.link || null,
    })), { unit: 'Zdjęcia' }));
    const gminas = card('Gminy', shareTable((pl.gminas || []).map(g => ({
      label: g.name, value: g.photos, tag: num(g.views) + ' wyśw. · ' + num(g.likes) + ' polub.',
    })), { unit: 'Zdjęcia' }));
    const low = card('Miejscowości z małą liczbą zdjęć', shareTable((pl.low_photos || []).map(v => ({
      label: v.name, value: v.photos, tag: v.gmina, link: v.link || null,
    })), { unit: 'Zdjęcia' }));
    const attractions = card('Najpopularniejsze atrakcje (kadry)', shareTable((p.items || []).slice(0, 8).map(it => ({
      label: it.title, value: it.views, tag: it.place || '', link: it.link,
    })), { unit: 'Wyświetlenia' }));

    el('stPanel-photos').innerHTML = kpis + ranking +
      `<div style="height:14px"></div>` +
      grid2([villages, gminas, low, attractions]) +
      `<div class="tr-card"><div class="tr-muted">Miejscowość = kategoria (podkategoria gminy). Atrakcja = pojedynczy kadr. ` +
      `Linki prowadzą do zdjęcia w galerii (widok zdjęcia). Zero oznacza brak zdarzeń, „—” brak danych do wyliczenia wskaźnika.</div></div>`;

    document.querySelectorAll('#stPanel-photos .st-sort').forEach(th => {
      th.addEventListener('click', () => {
        const k = th.getAttribute('data-sort');
        if(photoSort.key === k){ photoSort.dir *= -1; } else { photoSort.key = k; photoSort.dir = (k === 'title') ? 1 : -1; }
        renderPhotos(d);
      });
    });
  }

  /* -------------------------- ROZWÓJ SPOŁECZNOŚCI ----------------------- */
  function renderCommunity(d){
    const c = d.community || {};
    const tot = c.totals || {};
    const per = c.period || {};
    const life = c.photo_lifecycle || {};

    const kpis = `<div class="stats-row">` +
      kpi('Konta (razem)', num(tot.users), '+' + num(per.new_users) + ' w okresie') +
      kpi('Zdjęcia (razem)', num(tot.photos), '+' + num(c.photos_added) + ' zgłoszonych') +
      kpi('Komentarze (razem)', num(tot.comments), '+' + num(per.comments) + ' w okresie') +
      kpi('Polubienia zdjęć', num(tot.likes), '+' + num(per.likes) + ' w okresie') +
      kpi('Polubienia komentarzy', num(tot.comment_likes), '+' + num(per.comment_likes) + ' w okresie') +
      kpi('Ulubione (razem)', num(tot.favorites), '+' + num(per.favorites) + ' w okresie') +
      kpi('Obserwacje (razem)', num(tot.follows), '+' + num(per.follows) + ' w okresie') +
      kpi('Wiadomości (razem)', num(tot.messages), '+' + num(per.messages) + ' w okresie') +
      kpi('Aktywni użytkownicy', num(c.active_users)) +
      `</div>`;

    // Osobne wykresy i osobne skale. Przy wspólnym wykresie słupek nowych kont
    // (zwykle 1–2) był kilka pikseli obok słupków zdjęć (kilkadziesiąt), a gdy
    // zdjęć było więcej, nakładka zdjęć całkiem zasłaniała słupek kont.
    const usersGrowth = card('Nowe konta (dziennie) · w okresie: ' + num(per.new_users), barChart(c.series || [], [
      { key: 'users', label: 'Nowe konta', color: 'var(--gold)' },
    ]));
    const photosGrowth = card('Nowe zdjęcia (dziennie) · w okresie: ' + num(c.photos_added), barChart(c.series || [], [
      { key: 'photos', label: 'Nowe zdjęcia', color: 'var(--teal, #5a8a86)' },
    ]));
    const growth = grid2([usersGrowth, photosGrowth]);

    const lifecycle = card('Cykl życia zdjęć w okresie', table(
      [{ label: 'Zdarzenie' }, { label: 'Liczba', num: true }],
      [
        ['Dodane (wg dziennika moderacji)', num(life.added)],
        ['Zgłoszone (wg tabeli zdjęć)', num(c.photos_added)],
        ['Zatwierdzone', num(life.approved)],
        ['Odrzucone', num(life.rejected)],
        ['Usunięte', num(life.deleted)],
      ]
    ));

    const ranking = card('Ranking aktywności (punkty w okresie)', table(
      [{ label: 'Użytkownik' }, { label: 'Punkty w okresie', num: true }, { label: 'Akcje', num: true }, { label: 'Punkty razem', num: true }],
      (c.ranking || []).map(r => [esc(r.name), num(r.earned), num(r.actions), num(r.points)])
    ));

    el('stPanel-community').innerHTML = kpis + growth + `<div style="height:14px"></div>` +
      grid2([lifecycle, ranking]) +
      `<div class="tr-card"><div class="tr-muted">Wykresy nowych kont i nowych zdjęć mają własne skale, aby pojedyncze konto było widoczne obok większej liczby zdjęć. Statystyki aktywności nie pokazują treści wiadomości ani danych kontaktowych — wyłącznie liczby zbiorcze.</div></div>`;
  }

  /* ------------------------------ SEO I ŹRÓDŁA -------------------------- */
  function renderSeo(d){
    const s = d.seo || {};
    const refClasses = card('Źródła ruchu (klasy)', shareTable((s.ref_classes || []).map(x => ({ label: x.label, value: x.pageviews }))));
    const referrers = card('Źródła ruchu (hosty zewnętrzne)', table(
      [{ label: 'Host' }, { label: 'Klasa' }, { label: 'Odsłony', num: true }],
      (s.referrers || []).map(r => [`<span class="st-tag">${esc(r.host || '—')}</span>`, esc(r.classLabel), num(r.pageviews)])
    ));
    const topUrls = card('Najczęściej odwiedzane adresy', table(
      [{ label: 'Adres' }, { label: 'Odsłony', num: true }],
      (s.top_urls || []).map(u => [`<span class="st-tag">${esc(u.path)}</span>`, num(u.pageviews)])
    ));
    const entry = card('Strony wejścia', table(
      [{ label: 'Adres' }, { label: 'Wejścia', num: true }],
      (s.entry_pages || []).map(u => [`<span class="st-tag">${esc(u.path)}</span>`, num(u.entries)])
    ) + `<div class="st-kpi-sub" style="margin-top:8px">${esc(s.entry_note || '')}</div>`);
    const notFound = card('Błędy 404 w okresie: ' + num(s.not_found_period), table(
      [{ label: 'Adres' }, { label: 'Trafienia', num: true }],
      (s.top_not_found || []).map(u => [`<span class="st-tag">${esc(u.path)}</span>`, num(u.count)])
    ));
    const gscInfo = s.gsc || {};
    const gscConnected = gscInfo.connected === true || gscInfo.is_connected === true;
    const gscUpdated = gscInfo.updated_at || gscInfo.last_updated || gscInfo.last_sync_at || null;
    const gscUpdatedLabel = gscUpdated
      ? dateLabel(new Date(gscUpdated).getTime())
      : 'Brak aktualizacji — dane nie były jeszcze pobierane';
    const gsc = card('Google Search Console', `
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px;">
        <span class="st-tag" style="color:${gscConnected ? '#16834a' : '#b45309'}">${gscConnected ? 'Połączono' : 'Niepołączono'}</span>
        <span class="tr-muted">${gscConnected ? 'Konto Google jest podłączone.' : 'Konto Google nie jest podłączone.'}</span>
      </div>
      <div class="tr-muted">Ostatnia aktualizacja: ${esc(gscUpdatedLabel)}</div>
    `);

    el('stPanel-seo').innerHTML = grid2([refClasses, referrers, topUrls, entry, notFound, gsc]) +
      `<div class="tr-card" style="margin-top:14px"><h3>Jak klasyfikowane są źródła</h3><div class="tr-muted">${esc(s.classification || '')}</div></div>`;
  }

  /* ----------------------------- STAN TECHNICZNY ------------------------ */
  function renderTech(d){
    const t = d.technical || {};
    const db = t.database || {};
    const env = t.environment || {};
    const q = t.queues || {};
    const err = t.errors || {};
    const up = t.uploads || {};

    const kpis = `<div class="stats-row">` +
      kpi('Rozmiar bazy', num2(db.size_mb) + ' MB') +
      kpi('Tabele w bazie', num(db.tables)) +
      kpi('Kolejka zdjęć', num(q.photos)) +
      kpi('Kolejka miejscowości', num(q.categories)) +
      kpi('Błędy 404 (okres)', num(err.not_found_period)) +
      kpi('Błędy PHP (okres)', num(err.php_fatal_period)) +
      `</div>`;

    const dirRows = ['photos', 'avatars', 'cache'].map(k => {
      const v = up[k] || {};
      return [k, v.exists ? 'tak' : 'brak', num(v.files), bytes(v.bytes)];
    });
    const dirs = card('Miejsce na dysku', table(
      [{ label: 'Katalog' }, { label: 'Istnieje' }, { label: 'Pliki', num: true }, { label: 'Rozmiar', num: true }],
      dirRows
    ));

    const errTable = card('Błędy techniczne', table(
      [{ label: 'Rodzaj' }, { label: 'W okresie', num: true }, { label: 'Łącznie', num: true }],
      [
        ['HTTP 404 (nie znaleziono)', num(err.not_found_period), num(err.not_found_total)],
        ['Błędy PHP (fatalne)', num(err.php_fatal_period), num(err.php_fatal_total)],
        ['Błędy zapisu zdjęć', num(err.upload_error_period), num(err.upload_error_total)],
      ]
    ));

    const recent = card('Ostatnie zdarzenia techniczne', table(
      [{ label: 'Dzień' }, { label: 'Rodzaj' }, { label: 'Adres' }, { label: 'Opis' }],
      (t.recent_errors || []).map(e => [esc(e.day), `<span class="st-tag">${esc(e.kind)}</span>`, esc(e.path), esc(e.detail)])
    ));

    const respTime = card('Czasy odpowiedzi', `<div class="st-empty">${esc((t.response_times && t.response_times.note) || 'Nie mierzone.')}</div>`);

    const environment = card('Środowisko', table(
      [{ label: 'Parametr' }, { label: 'Wartość' }],
      [
        ['PHP', esc(env.php_version || '')],
        ['MySQL/MariaDB', esc(env.mysql_version || '')],
        ['Czas serwera', esc(env.server_time || '')],
        ['Strefa czasowa', esc(env.timezone || '')],
      ]
    ));

    el('stPanel-tech').innerHTML = kpis + grid2([environment, dirs, errTable, respTime]) +
      `<div style="height:14px"></div>` + recent;
  }

  let gscLoaded = false;
  let gscLoading = false;

  function setTab(tab){
    activeTab = tab;
    try{ sessionStorage.setItem('zm-admin-stats-tab', tab); }catch(_){} 
    document.querySelectorAll('#stTabs .tool-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-st') === tab));
    ['overview', 'traffic', 'photos', 'community', 'seo', 'gsc', 'tech'].forEach(k => {
      const p = el('stPanel-' + k);
      if(p) p.style.display = (k === tab) ? 'block' : 'none';
    });
    if(tab === 'gsc' && !gscLoaded && !gscLoading) loadGsc();
  }

  async function loadGsc(forceRefresh = false){
    const panel = el('stPanel-gsc');
    if(!panel || gscLoading) return;
    gscLoading = true;
    panel.innerHTML = empty(forceRefresh ? 'Odświeżanie danych Google Search Console…' : 'Wczytywanie zapisanych danych Google Search Console…');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try{
      const response = await fetch(`${API}?action=gsc_report&range=${encodeURIComponent(gscRange)}${forceRefresh ? '&refresh=1' : ''}`, {credentials:'include', signal:controller.signal});
      if(response.status === 401){ window.zmSessionLost && window.zmSessionLost(); return; }
      const d = await response.json();
      if(!d.ok){
        const connect = !d.connected
          ? `<p class="tr-muted">Połącz konto Google, aby pobierać rzeczywiste dane.</p><p><a class="tool-btn" href="${API}?action=gsc_connect">Połącz z Google Search Console</a></p>`
          : `<p class="tr-muted">${esc(d.error || 'Nie udało się pobrać danych.')}</p>`;
        panel.innerHTML = card('Google Search Console', connect + `<p><button type="button" class="tool-btn" id="gscRetry">Sprawdź ponownie</button></p>`);
        const retry = el('gscRetry'); if(retry) retry.addEventListener('click', () => loadGsc(true));
        gscLoaded = true;
        return;
      }
      const s = d.summary || {};
      const kpis = `<div class="stats-row">` +
        kpi('Kliknięcia', num(s.clicks)) + kpi('Wyświetlenia', num(s.impressions)) +
        kpi('CTR', (Number(s.ctr || 0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%') +
        kpi('Średnia pozycja', Number(s.position || 0).toLocaleString('pl-PL',{maximumFractionDigits:1})) + `</div>`;
      const rows = (items, key) => table(
        [{label:'Fraza / adres'},{label:'Kliknięcia',num:true},{label:'Wyświetlenia',num:true},{label:'CTR'},{label:'Pozycja',num:true}],
        (items || []).map(x => [esc(x[key] || ''), num(x.clicks), num(x.impressions),
          (Number(x.ctr || 0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',
          Number(x.position || 0).toLocaleString('pl-PL',{maximumFractionDigits:1})])
      );
      const pct = v => v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + Number(v).toLocaleString('pl-PL',{maximumFractionDigits:1}) + '%';
      const deltaRows = items => (items || []).slice(0,10).map(x => [
        esc(x.query || x.page || ''),
        num(x.clicks), num(x.previous_clicks), (x.clicks_delta > 0 ? '+' : '') + num(x.clicks_delta),
        pct(x.clicks_delta_pct)
      ]);
      const comparison = d.summary_delta || {};
      const compare = card('Porównanie z poprzednim okresem (' + esc(d.range.previous_from || '') + ' — ' + esc(d.range.previous_to || '') + ')',
        table([{label:'Wskaźnik'},{label:'Zmiana',num:true}], [
          ['Kliknięcia', pct(comparison.clicks_pct)],
          ['Wyświetlenia', pct(comparison.impressions_pct)],
          ['CTR (różnica punktów proc.)', (Number(comparison.ctr_points || 0) > 0 ? '+' : '') + Number(comparison.ctr_points || 0).toLocaleString('pl-PL',{maximumFractionDigits:2}) + ' pp'],
          ['Pozycja (różnica)', Number(comparison.position_change || 0).toLocaleString('pl-PL',{maximumFractionDigits:1})]
        ]));
      const queries = card('Najpopularniejsze zapytania', rows(d.queries, 'query'));
      const pages = card('Najpopularniejsze strony', rows(d.pages, 'page'));
      const gains = card('Zapytania z największym wzrostem kliknięć', table(
        [{label:'Fraza'},{label:'Teraz',num:true},{label:'Poprzednio',num:true},{label:'Różnica',num:true},{label:'Zmiana'}],
        deltaRows(d.query_changes && d.query_changes.gains)));
      const drops = card('Zapytania z największym spadkiem kliknięć', table(
        [{label:'Fraza'},{label:'Teraz',num:true},{label:'Poprzednio',num:true},{label:'Różnica',num:true},{label:'Zmiana'}],
        deltaRows(d.query_changes && d.query_changes.drops)));
      const pageGains = card('Strony z największym wzrostem kliknięć', table(
        [{label:'Strona'},{label:'Teraz',num:true},{label:'Poprzednio',num:true},{label:'Różnica',num:true},{label:'Zmiana'}],
        deltaRows(d.page_changes && d.page_changes.gains)));
      const pageDrops = card('Strony z największym spadkiem kliknięć', table(
        [{label:'Strona'},{label:'Teraz',num:true},{label:'Poprzednio',num:true},{label:'Różnica',num:true},{label:'Zmiana'}],
        deltaRows(d.page_changes && d.page_changes.drops)));
      const lowCtr = card('Dużo wyświetleń, niski CTR (poniżej 2%)', table(
        [{label:'Strona'},{label:'Wyświetlenia',num:true},{label:'Kliknięcia',num:true},{label:'CTR'},{label:'Pozycja',num:true}],
        (d.low_ctr_pages || []).map(x => [esc(x.page), num(x.impressions), num(x.clicks),
          (Number(x.ctr || 0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',
          Number(x.position || 0).toLocaleString('pl-PL',{maximumFractionDigits:1})])));
      const opportunities = card('Frazy na pozycjach 4–20 (potencjał poprawy)', table(
        [{label:'Fraza'},{label:'Wyświetlenia',num:true},{label:'Kliknięcia',num:true},{label:'CTR'},{label:'Pozycja',num:true}],
        (d.position_opportunities || []).map(x => [esc(x.query), num(x.impressions), num(x.clicks),
          (Number(x.ctr || 0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',
          Number(x.position || 0).toLocaleString('pl-PL',{maximumFractionDigits:1})])));
      const devices = card('Urządzenia', table([{label:'Urządzenie'},{label:'Kliknięcia',num:true},{label:'Wyświetlenia',num:true}],
        (d.devices || []).map(x => [esc(x.device), num(x.clicks), num(x.impressions)])));
      const alpha3to2 = {POL:'PL',USA:'US',GBR:'GB',DEU:'DE',FRA:'FR',UKR:'UA',CZE:'CZ',SVK:'SK',NLD:'NL',NOR:'NO',SWE:'SE',DNK:'DK',FIN:'FI',ESP:'ES',ITA:'IT',AUT:'AT',CHE:'CH',IRL:'IE',BEL:'BE',PRT:'PT',ROU:'RO',HUN:'HU',LTU:'LT',LVA:'LV',EST:'EE',GRC:'GR',HRV:'HR',BGR:'BG',ISL:'IS',CAN:'CA',MEX:'MX',BRA:'BR',ARG:'AR',CHL:'CL',COL:'CO',AUS:'AU',NZL:'NZ',JPN:'JP',CHN:'CN',KOR:'KR',IND:'IN',IDN:'ID',THA:'TH',VNM:'VN',SGP:'SG',MYS:'MY',PHL:'PH',TUR:'TR',ISR:'IL',SAU:'SA',ARE:'AE',EGY:'EG',ZAF:'ZA',NGA:'NG',PAK:'PK',BGD:'BD',TWN:'TW',HKG:'HK'};
      const countryName = code => { if(code==='POL') return 'Polska'; const alpha2=alpha3to2[code]||code; try { return new Intl.DisplayNames(['pl'], {type:'region'}).of(alpha2) || code; } catch(_) { return code; } };
      const polishCountry = (d.countries || []).filter(x => x.country === 'POL');
      const otherCountries = (d.countries || []).filter(x => x.country !== 'POL');
      const countries = card('Kraje: Polska i pozostałe kraje świata', table([{label:'Kraj'},{label:'Kliknięcia',num:true},{label:'Wyświetlenia',num:true}],
        [...polishCountry, ...otherCountries].map(x => [esc(countryName(x.country)), num(x.clicks), num(x.impressions)])));
      const geoLimit = card('Dokładność danych lokalizacyjnych', '<p class="tr-muted">Search Console udostępnia lokalizację na poziomie kraju. Nie przekazuje miasta, gminy ani województwa, dlatego tych danych nie da się wiarygodnie rozbić w tym raporcie. Do tak szczegółowej geografii potrzebne są dane z narzędzia analitycznego strony, które rejestruje lokalizację użytkowników.</p>');

      const image = d.image_summary || {};
      const imageCard = card('Wyszukiwarka Google Grafika', '<div class="stats-row">' +
        kpi('Kliknięcia', num(image.clicks)) + kpi('Wyświetlenia', num(image.impressions)) +
        kpi('CTR', (Number(image.ctr || 0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%') + '</div>');
      const sitemapCard = card('Sitemapy zgłoszone w Search Console', table(
        [{label:'Adres'},{label:'Stan'},{label:'Błędy',num:true},{label:'Ostrzeżenia',num:true},{label:'Przesłane',num:true},{label:'Zaindeksowane',num:true}],
        (d.sitemaps || []).map(x => [esc(x.path), x.is_pending ? 'oczekuje' : 'przetworzona', num(x.errors), num(x.warnings),
          num((x.contents || []).reduce((n,y) => n + Number(y.submitted || 0), 0)),
          num((x.contents || []).reduce((n,y) => n + Number(y.indexed || 0), 0))])));
      const series = card('Dane dzienne', table([{label:'Data'},{label:'Kliknięcia',num:true},{label:'Wyświetlenia',num:true},{label:'CTR'},{label:'Pozycja',num:true}],
        (d.series || []).map(x => [esc(x.date), num(x.clicks), num(x.impressions),
          (Number(x.ctr || 0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',
          Number(x.position || 0).toLocaleString('pl-PL',{maximumFractionDigits:1})])));
      const chartRows = (d.series || []).slice(-90);
      const maxClicks = Math.max(1, ...chartRows.map(x => Number(x.clicks || 0)));
      const dailyChart = card('Wykres kliknięć (ostatnie maks. 90 dni)', `<div style="display:grid;gap:6px">` +
        chartRows.map(x => `<div style="display:grid;grid-template-columns:82px minmax(40px,1fr) 44px;gap:8px;align-items:center"><span class="tr-muted">${esc(x.date)}</span><div style="height:9px;background:var(--bg-alt);border-radius:8px;overflow:hidden"><div style="height:9px;width:${Math.max(0,Math.min(100,Number(x.clicks || 0)/maxClicks*100))}%;background:var(--teal);border-radius:8px"></div></div><b style="text-align:right">${num(x.clicks)}</b></div>`).join('') + `</div>`);
      const imageKpi = kpi('Google Grafika — kliknięcia', num(image.clicks), num(image.impressions) + ' wyświetleń');
      const cacheStamp = d.cached_at ? new Date(Number(d.cached_at)*1000).toLocaleString('pl-PL',{dateStyle:'short',timeStyle:'short'}) : 'brak informacji';
      const cacheStatus = d.stale ? '<p class="tr-muted">Nie udało się odświeżyć danych w Google. Pokazuję ostatni poprawny zapis.</p>' : '';
      panel.innerHTML = `<div class="filters-row"><label class="tr-muted" for="gscRange">Zakres danych</label><select class="filter-select" id="gscRange"><option value="7d">7 dni</option><option value="28d">28 dni</option><option value="90d">90 dni</option><option value="12m">12 miesięcy</option></select><span class="tr-muted">${esc(d.range.from)} — ${esc(d.range.to)}</span><span class="tr-muted">Zapisano: ${esc(cacheStamp)}</span><button type="button" class="tool-btn" id="gscRefresh">Odśwież z Google</button><button type="button" class="tool-btn" id="gscPdfBtn" title="Wygeneruj zewnętrzny raport Google Search Console do PDF">PDF / Drukuj raport zewnętrzny</button></div>` + cacheStatus +
        kpis + compare +
        grid2([gains, drops, pageGains, pageDrops, lowCtr, opportunities, queries, pages, devices, countries, geoLimit, imageCard, sitemapCard, dailyChart, series]) +
        `<p class="tr-muted">${esc(d.note || '')}</p>`;
      const rangeSelect = el('gscRange'); if(rangeSelect){ rangeSelect.value = gscRange; rangeSelect.addEventListener('change', () => { gscRange = rangeSelect.value; loadGsc(); }); }
      const refresh = el('gscRefresh'); if(refresh) refresh.addEventListener('click', () => loadGsc(true));
      const pdf = el('gscPdfBtn'); if(pdf) pdf.addEventListener('click', printGscReport);
      gscLoaded = true;
    }catch(error){
      panel.innerHTML = empty(error && error.name === 'AbortError' ? 'Serwer zbyt długo odpowiadał. Pozostałe statystyki działają niezależnie. Spróbuj ponownie.' : 'Nie udało się połączyć z Google Search Console.');
      gscLoaded = true;
    }finally{
      clearTimeout(timeout);
      gscLoading = false;
    }
  }

async function printGscReport(){
  const win = window.open('', '_blank');
  if(!win){ window.zmToast && window.zmToast('Przeglądarka zablokowała nowe okno raportu. Zezwól na wyskakujące okna dla panelu.'); return; }
  win.opener = null;
  const fmt = n => Number(n || 0).toLocaleString('pl-PL',{maximumFractionDigits:2});
  const pct = n => n === null || n === undefined ? '—' : (Number(n)>0?'+':'')+fmt(n)+'%';
  const escHtml = s => String(s ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const tableHtml = (headers, rows) => `<table><thead><tr>${headers.map(h=>`<th>${escHtml(h)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(row=>`<tr>${row.map(v=>`<td>${v}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${headers.length}" class="empty">Brak danych w wybranym zakresie</td></tr>`}</tbody></table>`;
  const section = (title, html) => `<section><h2>${escHtml(title)}</h2>${html}</section>`;
  try{
    win.document.open();
    win.document.write('<!doctype html><html lang="pl"><meta charset="utf-8"><title>Raport Google Search Console — zMogilna</title><body style="font:14px Arial;padding:32px">Pobieranie raportu…</body></html>');
    win.document.close();
    const response = await fetch(`${API}?action=gsc_report&range=${encodeURIComponent(gscRange)}`,{credentials:'include'});
    if(response.status === 401){ win.close(); window.zmSessionLost && window.zmSessionLost(); return; }
    const d = await response.json();
    if(!d.ok) throw new Error(d.error || 'Nie udało się pobrać danych.');
    const summary=d.summary||{}, delta=d.summary_delta||{}, image=d.image_summary||{};
    const metricRows=[
      ['Kliknięcia',fmt(summary.clicks),pct(delta.clicks_pct)],
      ['Wyświetlenia',fmt(summary.impressions),pct(delta.impressions_pct)],
      ['CTR',(Number(summary.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',fmt(delta.ctr_points)+' pkt proc.'],
      ['Średnia pozycja',fmt(summary.position),fmt(delta.position_change)]
    ];
    const rowMetrics=(items,key)=>(items||[]).map(x=>[
      escHtml(x[key]||''),fmt(x.clicks),fmt(x.impressions),(Number(x.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',fmt(x.position)
    ]);
    const changeRows=(items,key)=>(items||[]).slice(0,10).map(x=>[
      escHtml(x[key]||''),fmt(x.clicks),fmt(x.previous_clicks),(Number(x.clicks_delta)>0?'+':'')+fmt(x.clicks_delta),pct(x.clicks_delta_pct)
    ]);
    const basicHeaders=['Zapytanie / strona','Kliknięcia','Wyświetlenia','CTR','Śr. pozycja'];
    const changeHeaders=['Zapytanie / strona','Teraz','Poprzednio','Różnica kliknięć','Zmiana'];
    const low=(d.low_ctr_pages||[]).map(x=>[escHtml(x.page),fmt(x.impressions),fmt(x.clicks),(Number(x.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',fmt(x.position)]);
    const opp=(d.position_opportunities||[]).map(x=>[escHtml(x.query),fmt(x.impressions),fmt(x.clicks),(Number(x.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',fmt(x.position)]);
    const daily=(d.series||[]).map(x=>[escHtml(x.date),fmt(x.clicks),fmt(x.impressions),(Number(x.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%',fmt(x.position)]);
    const device=(d.devices||[]).map(x=>[escHtml(x.device),fmt(x.clicks),fmt(x.impressions)]);
    const alpha3to2={POL:'PL',USA:'US',GBR:'GB',DEU:'DE',FRA:'FR',UKR:'UA',CZE:'CZ',SVK:'SK',NLD:'NL',NOR:'NO',SWE:'SE',DNK:'DK',FIN:'FI',ESP:'ES',ITA:'IT',AUT:'AT',CHE:'CH',IRL:'IE',BEL:'BE',PRT:'PT',ROU:'RO',HUN:'HU',LTU:'LT',LVA:'LV',EST:'EE',GRC:'GR',HRV:'HR',BGR:'BG',ISL:'IS',CAN:'CA',MEX:'MX',BRA:'BR',ARG:'AR',CHL:'CL',COL:'CO',AUS:'AU',NZL:'NZ',JPN:'JP',CHN:'CN',KOR:'KR',IND:'IN',IDN:'ID',THA:'TH',VNM:'VN',SGP:'SG',MYS:'MY',PHL:'PH',TUR:'TR',ISR:'IL',SAU:'SA',ARE:'AE',EGY:'EG',ZAF:'ZA',NGA:'NG',PAK:'PK',BGD:'BD',TWN:'TW',HKG:'HK'};
    const countryName=code=>{if(code==='POL')return 'Polska';const c=alpha3to2[code]||code;try{return new Intl.DisplayNames(['pl'],{type:'region'}).of(c)||code;}catch(_){return code;}};
    const country=(d.countries||[]).map(x=>[escHtml(countryName(x.country)),fmt(x.clicks),fmt(x.impressions)]);
    const sitemapRows=(d.sitemaps||[]).map(x=>[
      escHtml(x.path),x.is_pending?'Oczekuje':'Przetworzona',fmt(x.errors),fmt(x.warnings),
      fmt((x.contents||[]).reduce((n,y)=>n+Number(y.submitted||0),0)),
      fmt((x.contents||[]).reduce((n,y)=>n+Number(y.indexed||0),0))
    ]);
    const generated=new Date().toLocaleString('pl-PL');
    const html=`<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Raport Google Search Console — zMogilna</title>
    <style>
    *{box-sizing:border-box}body{font:13px/1.45 Arial,Helvetica,sans-serif;color:#20252b;margin:0;background:#fff}
    main{max-width:1100px;margin:0 auto;padding:34px}header{border-bottom:3px solid #263b45;padding-bottom:18px;margin-bottom:22px}
    h1{font-size:27px;margin:0 0 6px}h2{font-size:17px;margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid #d9dfe2}
    p{margin:4px 0;color:#52616a}.meta{font-size:12px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:18px 0}
    .kpi{border:1px solid #d9dfe2;border-radius:7px;padding:12px}.kpi span{display:block;color:#64727a;font-size:11px}.kpi b{display:block;font-size:22px;margin-top:4px}
    section{margin:24px 0;break-inside:avoid}table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed}
    th,td{border:1px solid #d9dfe2;padding:7px 8px;text-align:left;vertical-align:top;overflow-wrap:anywhere}
    th{background:#f0f3f4;font-weight:700}tr{break-inside:avoid}.empty{text-align:center;color:#68757c}
    .note{font-size:11px;color:#59666c;margin-top:22px;border-top:1px solid #d9dfe2;padding-top:10px}
    .actions{display:flex;justify-content:flex-end;margin:0 0 16px}.actions button{border:1px solid #60727b;background:#fff;padding:9px 14px;border-radius:5px;cursor:pointer}
    @page{size:A4 landscape;margin:12mm}@media print{main{max-width:none;padding:0}.actions{display:none}section{break-inside:auto}h2{break-after:avoid}}
    @media(max-width:700px){main{padding:16px}.kpis{grid-template-columns:repeat(2,1fr)}}
    </style></head><body><main>
    <div class="actions"><button onclick="window.print()">Drukuj / Zapisz jako PDF</button></div>
    <header><h1>Raport ruchu z wyszukiwarki Google</h1><p><b>zMogilna.pl</b> · Google Search Console</p>
    <p class="meta">Zakres danych: ${escHtml(d.range.from)} — ${escHtml(d.range.to)} · Wygenerowano: ${escHtml(generated)}</p>
    <p class="meta">Porównanie: ${escHtml(d.range.previous_from)} — ${escHtml(d.range.previous_to)}</p></header>
    <div class="kpis">${[['Kliknięcia',fmt(summary.clicks)],['Wyświetlenia',fmt(summary.impressions)],['CTR',(Number(summary.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%'],['Średnia pozycja',fmt(summary.position)]].map(x=>`<div class="kpi"><span>${escHtml(x[0])}</span><b>${escHtml(x[1])}</b></div>`).join('')}</div>
    ${section('Porównanie z poprzednim okresem',tableHtml(['Wskaźnik','Bieżący okres','Zmiana'],metricRows))}
    ${section('Najpopularniejsze zapytania',tableHtml(basicHeaders,rowMetrics(d.queries,'query')))}
    ${section('Najpopularniejsze strony',tableHtml(basicHeaders,rowMetrics(d.pages,'page')))}
    ${section('Zapytania z największym wzrostem kliknięć',tableHtml(changeHeaders,changeRows(d.query_changes&&d.query_changes.gains,'query')))}
    ${section('Zapytania z największym spadkiem kliknięć',tableHtml(changeHeaders,changeRows(d.query_changes&&d.query_changes.drops,'query')))}
    ${section('Strony z największym wzrostem kliknięć',tableHtml(changeHeaders,changeRows(d.page_changes&&d.page_changes.gains,'page')))}
    ${section('Strony z największym spadkiem kliknięć',tableHtml(changeHeaders,changeRows(d.page_changes&&d.page_changes.drops,'page')))}
    ${section('Strony z dużą liczbą wyświetleń i niskim CTR',tableHtml(['Strona','Wyświetlenia','Kliknięcia','CTR','Śr. pozycja'],low))}
    ${section('Frazy na pozycjach 4–20',tableHtml(['Fraza','Wyświetlenia','Kliknięcia','CTR','Śr. pozycja'],opp))}
    ${section('Urządzenia',tableHtml(['Urządzenie','Kliknięcia','Wyświetlenia'],device))}
    ${section('Kraje: Polska i pozostałe kraje świata',tableHtml(['Kraj','Kliknięcia','Wyświetlenia'],country))}
    ${section('Dokładność lokalizacji','<p>Search Console udostępnia lokalizację na poziomie kraju. Nie przekazuje miasta, gminy ani województwa.</p>')}
    ${section('Google Grafika',tableHtml(['Kliknięcia','Wyświetlenia','CTR'],[[fmt(image.clicks),fmt(image.impressions),(Number(image.ctr||0)*100).toLocaleString('pl-PL',{maximumFractionDigits:2})+'%']]))}
    ${section('Sitemapy w Google Search Console',tableHtml(['Adres','Stan','Błędy','Ostrzeżenia','Przesłane','Zaindeksowane'],sitemapRows))}
    ${section('Dane dzienne',tableHtml(['Data','Kliknięcia','Wyświetlenia','CTR','Śr. pozycja'],daily))}
    <p class="note">${escHtml(d.note||'Dane pochodzą z Google Search Console. Kliknięcia oznaczają przejścia z wyników wyszukiwania Google, a nie wszystkie wizyty w serwisie.')} Raport wygenerowany automatycznie przez panel administracyjny zMogilna.</p>
    </main></body></html>`;
    win.document.open();win.document.write(html);win.document.close();
    win.focus();
  }catch(err){
    win.document.open();win.document.write('<!doctype html><meta charset="utf-8"><p>Nie udało się wygenerować raportu: '+escHtml(err && err.message ? err.message : 'Nieznany błąd')+'</p>');win.document.close();
  }
}

  function renderAll(){
    if(!data) return;
    renderOverview(data);
    renderTraffic(data);
    renderPhotos(data);
    renderCommunity(data);
    renderSeo(data);
    renderTech(data);
    setTab(activeTab);
  }

  function rangeQuery(){
    if(range === 'custom'){
      const f = el('stFrom') ? el('stFrom').value : '';
      const t = el('stTo') ? el('stTo').value : '';
      return `from=${encodeURIComponent(f)}&to=${encodeURIComponent(t)}`;
    }
    return 'range=' + encodeURIComponent(range);
  }

  async function load(){
    const note = el('stNote');
    if(note) note.textContent = 'Wczytywanie…';
    let d = null;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try{
      const res = await fetch(`${API}?action=stats_report&${rangeQuery()}`, { credentials:'include', signal:controller.signal });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return; }
      if(!res.ok) throw new Error('HTTP ' + res.status);
      d = await res.json();
    }catch(error){
      d = null;
      const message = error && error.name === 'AbortError'
        ? 'Serwer nie odpowiedział w ciągu 20 sekund. Sprawdź połączenie z admin-api.php.'
        : 'Nie udało się pobrać danych statystyk z admin-api.php.';
      ['overview', 'traffic', 'photos', 'community', 'seo', 'tech'].forEach(k => {
        const p = el('stPanel-' + k);
        if(p) p.innerHTML = empty(message);
      });
      if(note) note.textContent = message;
      return;
    }finally{
      clearTimeout(timeout);
    }

    if(!d || !d.ok){
      ['overview', 'traffic', 'photos', 'community', 'seo', 'tech'].forEach(k => {
        const p = el('stPanel-' + k);
        if(p) p.innerHTML = empty('Nie udało się wczytać statystyk.');
      });
      if(note) note.textContent = 'Nie udało się wczytać statystyk.';
      return;
    }

    data = d;
    renderAll();

    const meta = el('stMeta');
    if(meta){
      const f = d.range ? d.range.from : '';
      const tt = d.range ? d.range.to : '';
      meta.textContent = `${f} — ${tt} (${d.range && d.range.label ? d.range.label : ''})`;
    }
    if(note){
      note.textContent = `Okres: ${d.range.from} — ${d.range.to} · ${d.range.days} dni. ` +
        `Liczby bez botów i bez wizyt administratora. Dane źródłowe z bazy — bez wartości szacunkowych.` +
        (d.range.series_aggregated ? ' Wykresy zagregowane do słupków wielodniowych (długi okres).' : '');
    }
  }

  document.querySelectorAll('#stTabs .tool-btn').forEach(b => {
    b.addEventListener('click', () => setTab(b.getAttribute('data-st')));
  });

  el('stRange').addEventListener('change', () => {
    range = el('stRange').value;
    const custom = el('stCustom');
    if(custom) custom.style.display = (range === 'custom') ? 'inline-flex' : 'none';
    if(range !== 'custom') load();
  });
  el('stApply').addEventListener('click', () => load());
  el('stRefresh').addEventListener('click', () => load());
  el('stCsvBtn').addEventListener('click', () => {
    const q = (range === 'custom') ? rangeQuery() : 'range=' + encodeURIComponent(range);
    window.location.href = `traffic-export.php?dataset=series&${q}`;
  });
  el('stPdfBtn').addEventListener('click', () => {
    const q = (range === 'custom') ? rangeQuery() : 'range=' + encodeURIComponent(range);
    window.open(`traffic-print.php?${q}`, '_blank', 'noopener');
  });

  window.zmTraffic = { refresh(){ load(); } };

  // Główny skrypt panelu przywraca zapamiętaną zakładkę wcześniej niż
  // ten moduł się rejestruje. Jeśli przywrócił Statystyki, uruchom raport
  // dopiero teraz, kiedy wszystkie elementy i funkcje są już dostępne.
  const trafficView = el('trafficView');
  if(trafficView && trafficView.style.display !== 'none'){
    setTab(activeTab);
    load();
  }
})();
</script>

<script>
/* ------------------------------------------------------------------
   Centrum bezpieczeństwa — moduł panelu.
   Czyta status systemów (HTTPS/Let's Encrypt, HSTS, nagłówki, ochrona
   logowania, dziennik), pozwala zapisać konfigurację i uruchomić test
   certyfikatu + nagłówków na żywo.
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const csrf = () => (window.zmGetCsrf ? window.zmGetCsrf() : '');
  const num = n => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('pl-PL');
  const p2 = n => String(n).padStart(2, '0');
  const fmtDateTime = ms => { if(!ms) return '—'; const d = new Date(ms); return `${p2(d.getDate())}.${p2(d.getMonth()+1)}.${d.getFullYear()} ${p2(d.getHours())}:${p2(d.getMinutes())}`; };

  function setModule(name, cls, detail){
    const m = document.querySelector(`.sec-module[data-mod="${name}"]`);
    if(!m) return;
    m.className = `sec-module ${cls}`;
    const st = m.querySelector('.sec-mod-status');
    st.textContent = cls === 'ok' ? '✓' : (cls === 'warn' ? '!' : (cls === 'bad' ? '×' : '—'));
    m.querySelector('.sec-mod-detail').textContent = detail;
  }

  function renderModules(d){
    const cfg = d.config, last = d.lastcheck;
    const tls = (last && last.tls && last.tls.ok) ? last.tls : null;

    let tlsTxt = '';
    if(tls){
      tlsTxt = `Certyfikat od ${esc(tls.issuer || tls.subject || '?')}, ważny do ${fmtDateTime(tls.valid_to * 1000)} — jeszcze ${tls.days_left} dni (${esc(tls.protocol || 'TLS')}).`;
      const sanOk = tls.san && tls.san.length ? ` Obsługuje: ${esc(tls.san.join(', '))}.` : '';
      tlsTxt += sanOk;
    }

    if(d.https){
      setModule('https', tls ? 'ok' : 'ok', tlsTxt || 'Połączenie szyfrowane (HTTPS).');
    } else if(cfg.force_https){
      setModule('https', 'bad', `Połączenie to jest po HTTP, mimo wymuszenia HTTPS.${tlsTxt ? ' ' + tlsTxt : ''}`);
    } else {
      setModule('https', 'warn',
        'Serwis działa po HTTP — na produkcji dodaj certyfikat Let\'s Encrypt (np. przez certbot), a wtedy włącz „Wymuszaj HTTPS”.' +
        (tlsTxt ? ' ' + tlsTxt : ''));
    }

    if(!cfg.force_https){
      setModule('hsts', 'off', 'Wyłączone — HSTS wysyłamy dopiero gdy HTTPS jest wymuszane.');
    } else if(!cfg.hsts_enabled){
      setModule('hsts', 'off', 'Wyłączone w konfiguracji.');
    } else if(d.https){
      const mode = cfg.hsts_preload ? ' + includeSubDomains + preload' : (cfg.hsts_subdomains ? ' + includeSubDomains' : '');
      const sent = last && last.http && last.http.ok && last.http.headers && last.http.headers['strict-transport-security'];
      setModule('hsts', 'ok', `Włączone — max-age=${num(cfg.hsts_max_age)} s${mode}. ${sent ? 'Nagłówek widoczny w odpowiedzi.' : 'Ostatni test nie wykrył nagłówka.'}`);
    } else {
      setModule('hsts', 'warn', 'Włączone w konfiguracji, ale serwis nie odpowiada po HTTPS.');
    }

    const exp = d.expected_headers || {};
    const keys = Object.keys(exp);
    if(last && last.http && last.http.ok && last.http.headers){
      const have = [], miss = [];
      keys.forEach(h => ((last.http.headers[h] !== undefined) ? have : miss).push(exp[h]));
      setModule('headers', miss.length ? 'warn' : 'ok',
        `${have.length}/${keys.length} nagłówków w odpowiedzi.` +
        (miss.length ? ` Brak: ${miss.join(', ')}.` : ' Wszystkie obecne.'));
    } else {
      setModule('headers', messTxt(last));
    }

    const sessH = Math.round(cfg.session_ttl_seconds / 3600);
    const sessTxt = sessH >= 48 ? Math.round(sessH / 24) + ' dni' : sessH + ' h';
    setModule('login', 'ok',
      `${cfg.login_max_attempts} prób → blokada konta ${cfg.login_lock_seconds} s; ban IP po ${cfg.ip_max_fails} próbach w ${Math.round(cfg.ip_window_seconds / 60)} min; silne hasło min. ${cfg.min_password_length} znaków; sesja ${sessTxt}.`);

    setModule('log', cfg.events_enabled ? 'ok' : 'off',
      cfg.events_enabled
        ? `Dziennik zapisuje zdarzenia (24h: ${num(d.stats.events_24h)}, retencja ${cfg.log_retention_days} dni).`
        : 'Wyłączone w konfiguracji.');
  }

  function messTxt(last){
    if(!last) return 'Uruchom „Test certyfikatu i nagłówków”, aby sprawdzić nagłówki w odpowiedzi serwera.';
    if(!last.http) return 'Test nie pobrał odpowiedzi serwera.';
    if(last.http.error) return 'Odpowiedź serwera: ' + esc(String(last.http.error)) + '.';
    if(last.http.ok) return 'Brak wyników testu nagłówków.';
    return 'Test nagłówków nie zakończył się sukcesem.';
  }

  function fillConfig(cfg){
    el('secCfgForceHttps').checked = !!cfg.force_https;
    el('secCfgHsts').checked = !!cfg.hsts_enabled;
    el('secCfgHstsMaxAge').value = cfg.hsts_max_age;
    el('secCfgHstsPreload').checked = !!cfg.hsts_preload;
    el('secCfgHstsBox').style.display = cfg.force_https ? 'block' : 'none';
    el('secCfgMinPasswordLen').value = cfg.min_password_length;
    el('secCfgLoginMaxAttempts').value = cfg.login_max_attempts;
    el('secCfgLoginLockSeconds').value = cfg.login_lock_seconds;
    el('secCfgSessionTtl').value = cfg.session_ttl_seconds;
    el('secCfgLogRetentionDays').value = cfg.log_retention_days;
    el('secCfgEventsEnabled').checked = !!cfg.events_enabled;
  }

  function readConfig(){
    return {
      force_https: el('secCfgForceHttps').checked,
      hsts_enabled: el('secCfgHsts').checked,
      hsts_max_age: parseInt(el('secCfgHstsMaxAge').value, 10) || 0,
      hsts_subdomains: el('secCfgHstsPreload').checked,
      hsts_preload: el('secCfgHstsPreload').checked,
      min_password_length: parseInt(el('secCfgMinPasswordLen').value, 10) || 8,
      login_max_attempts: parseInt(el('secCfgLoginMaxAttempts').value, 10) || 5,
      login_lock_seconds: parseInt(el('secCfgLoginLockSeconds').value, 10) || 300,
      session_ttl_seconds: parseInt(el('secCfgSessionTtl').value, 10) || 2592000,
      log_retention_days: parseInt(el('secCfgLogRetentionDays').value, 10) || 90,
      events_enabled: el('secCfgEventsEnabled').checked,
    };
  }

  function renderChecks(checks){
    const box = el('secEnvChecks');
    if(!checks || !checks.length){ box.innerHTML = '<p class="muted">Brak danych.</p>'; return; }
    box.innerHTML = checks.map(c => `
      <div class="sec-check ${esc(c.status)}">
        <span class="dot"></span>
        <span class="t"><b>${esc(c.label)}</b><span>${esc(c.value)}${c.advice ? ' — ' + esc(c.advice) : ''}</span></span>
      </div>`).join('');
  }

  function renderLastTest(d, last){
    const box = el('secLastTest');
    const cfg = d.config;
    if(!last){
      box.innerHTML = '<p class="muted">Jeszcze nie uruchomiono. Kliknij „Test certyfikatu i nagłówków”.</p>';
      return;
    }
    let html = `<div style="font-size:11px;color:var(--ink-dim);margin-bottom:6px;">Test z ${fmtDateTime(last.at)} · host: ${esc(last.host || '')}${d.https ? ' · to połączenie: HTTPS' : ' · to połączenie: HTTP'}</div>`;
    if(last.tls && last.tls.ok){
      const bad = last.tls.days_left < 30;
      html += `<div class="sec-check ${bad ? 'warn' : 'ok'}"><span class="dot"></span><span class="t"><b>Certyfikat TLS</b><span>od ${esc(last.tls.issuer || '?')}; ważny do ${fmtDateTime(last.tls.valid_to * 1000)} (${last.tls.days_left} dni)${bad ? ' — odnowić wkrótce!' : ''}; ${esc(last.tls.protocol || 'TLS')}${last.tls.san && last.tls.san.length ? '; SAN: ' + esc(last.tls.san.join(', ')) : ''}</span></span></div>`;
    } else {
      const err = last.tls && last.tls.error ? last.tls.error : 'brak połączenia 443';
      html += `<div class="sec-check bad"><span class="dot"></span><span class="t"><b>Certyfikat TLS</b><span>${cfg.force_https ? 'Brak lub niedostępny port 443 — HTTPS nie działa.' : 'Brak certyfikatu (serwis planowany po HTTP).'} (${esc(String(err))})</span></span></div>`;
    }
    if(last.http){
      const hdrs = last.http.headers || {};
      if(last.http.ok){
        const lines = Object.keys(d.expected_headers || {}).map(h =>
          hdrs[h] !== undefined
            ? `<span style="display:inline-block;background:rgba(92,174,125,.15);color:#5cae7d;padding:1px 6px;border-radius:4px;margin:2px 3px 2px 0;font-size:10.5px;">✓ ${esc((d.expected_headers[h]))}</span>`
            : `<span style="display:inline-block;background:rgba(208,87,63,.15);color:#d0573f;padding:1px 6px;border-radius:4px;margin:2px 3px 2px 0;font-size:10.5px;">× ${esc((d.expected_headers[h]))}</span>`).join('');
        html += `<div class="sec-check ${Object.keys(d.expected_headers||{}).some(h => hdrs[h] === undefined) ? 'warn' : 'ok'}"><span class="dot"></span><span class="t"><b>Odpowiedź po HTTP ${last.http.status || '?'}</b><span style="display:block;margin-top:3px;">${lines}</span></span></div>`;
      } else {
        html += `<div class="sec-check bad"><span class="dot"></span><span class="t"><b>Odpowiedź serwera</b><span>${esc(String(last.http.error || 'no_response'))}</span></span></div>`;
      }
    }
    box.innerHTML = html;
  }

  function renderBans(bans){
    const box = el('secBansList');
    if(!bans || !bans.length){
      box.innerHTML = '<p class="muted">Brak aktywnych banów.</p>';
      return;
    }
    box.innerHTML = bans.map(b => {
      const active = Date.now() < new Date(String(b.banned_until)).getTime();
      return `<div class="sec-ban">
        <span><span class="ip">${esc(b.ip)}</span> <span class="why">${esc(b.reason || '')} · ${b.hits || 1}×${active ? ' · do ' + fmtDateTime(new Date(String(b.banned_until)).getTime()) : ''}</span></span>
        <button type="button" class="tool-btn mini" data-unban="${esc(b.ip)}">Usuń</button>
      </div>`;
    }).join('');
  }

  function renderEvents(events){
    const box = el('secEventsList');
    if(!events || !events.length){
      box.innerHTML = '<p class="muted">Brak zdarzeń. Zaloguj się kilka razy błędnie, aby zobaczyć ochronę w akcji.</p>';
      return;
    }
    box.innerHTML = events.map(e => `
      <div class="sec-evt">
        <span class="when">${fmtDateTime(e.created_at)}</span>
        <span><b>${esc(e.event)}</b><span class="sev ${sevCls(e.severity)}">${esc(e.severity)}</span>
          ${e.ip ? `<span class="who"> · ${esc(e.ip)}</span>` : ''}
          ${e.username ? `<span class="who"> · @${esc(e.username)}</span>` : ''}
          ${e.detail ? `<div style="color:var(--ink-dim);font-size:11.5px;margin-top:2px;">${esc(e.detail)}</div>` : ''}</span>
      </div>`).join('');
  }

  function sevCls(s){ return ['info','warn','danger'].includes(s) ? s : 'info'; }

  async function load(){
    const note = el('secNote');
    if(note) note.textContent = 'Wczytywanie…';
    let d = null;
    try{
      const res = await fetch(`${API}?action=security_status`, { credentials:'include' });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return; }
      d = await res.json();
    }catch(_){ d = null; }

    if(!d || !d.ok){
      if(note) note.textContent = 'Nie udało się pobrać danych bezpieczeństwa.';
      return;
    }
    state = d;

    el('secMeta').textContent = `${esc(d.host || '')} · PHP ${esc(d.php_version || '')} · to połączenie ${d.https ? 'HTTPS' : 'HTTP'}`;

    el('secKpiEvents').textContent = num(d.stats.events_24h);
    el('secKpiFails').textContent = num(d.stats.fails_24h);
    el('secKpiLocked').textContent = num(d.stats.locked_24h);
    el('secKpiBans').textContent = num(d.stats.bans_active);

    renderModules(d);
    fillConfig(d.config);
    renderLastTest(d, d.lastcheck);
    renderChecks(d.checks);
    renderBans(d.bans);
    renderEvents(d.events);

    if(note) note.textContent = `Audyt środowiska: ${d.rollup ? (d.rollup.counts.bad + d.rollup.counts.warn) : 0} uwag (${num(d.stats.events_total)} zdarzeń łącznie).`;
  }

  async function post(payload){
    try{
      const res = await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ csrf: csrf(), ...payload })
      });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const data = await res.json();
      if(!data.ok){ toast((data && data.message) || 'Nie udało się wykonać operacji.'); return null; }
      return data;
    }catch(_){
      toast('Błąd połączenia');
      return null;
    }
  }

  el('secRefreshBtn').addEventListener('click', load);
  el('secAuditRefresh').addEventListener('click', load);

  el('secSaveBtn').addEventListener('click', async () => {
    const d = await post({ action:'security_save', ...readConfig() });
    if(!d) return;
    toast('Konfiguracja zapisana.');
    load();
  });

  el('secTestBtn').addEventListener('click', async () => {
    const btn = el('secTestBtn');
    const old = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testuję…';
    try{
      const d = await post({ action:'security_test' });
      if(d && d.lastcheck){
        el('secLastTest').innerHTML = '<p class="muted">Test ukończony — odświeżam…</p>';
        load();
        toast('Test zakończony.');
      }
    }finally{
      btn.disabled = false;
      btn.textContent = old;
    }
  });

  el('secBanBtn').addEventListener('click', async () => {
    const d = await post({
      action:'security_ban_ip',
      ip: el('secBanIpInput').value.trim(),
      seconds: parseInt(el('secBanSeconds').value, 10) || 3600,
      reason: el('secBanReason').value.trim(),
    });
    if(!d) return;
    el('secBanIpInput').value = '';
    el('secBanReason').value = '';
    toast('Adres IP zablokowany.');
    load();
  });

  el('secBansList').addEventListener('click', async ev => {
    const btn = ev.target.closest('[data-unban]');
    if(!btn) return;
    const d = await post({ action:'security_unban_ip', ip: btn.dataset.unban });
    if(!d) return;
    toast('Blokada zdjęta.');
    load();
  });

  el('secClearEventsBtn').addEventListener('click', async () => {
    const d = await post({ action:'security_clear_events', days: 0 });
    if(!d) return;
    toast(`Wyczyszczono dziennik (${num(d.removed)} wpisów).`);
    load();
  });

  el('secClearOldBtn').addEventListener('click', async () => {
    const cfg = (state && state.config) ? state.config : {};
    const days = cfg.log_retention_days || 90;
    const d = await post({ action:'security_clear_events', days });
    if(!d) return;
    toast(`Przycięto dziennik do ${days} dni (usunięto ${num(d.removed)}).`);
    load();
  });

  el('secCfgForceHttps').addEventListener('change', () => {
    el('secCfgHstsBox').style.display = el('secCfgForceHttps').checked ? 'block' : 'none';
  });

  window.zmSecurity = { refresh(){ load(); } };
})();
</script>
<script>
/* ------------------------------------------------------------------
   Przerwa techniczna — moduł panelu.
   Jednym przełącznikiem blokuje stronę dla odwiedzających; zamiast
   treści pojawia się krótki komunikat i okno logowania (HTTP 503),
   w którym zalogować się może tylko administrator. Odczyt stanu:
   maintenance_status, zapis: maintenance_save.
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const el = id => document.getElementById(id);
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const csrf = () => (window.zmGetCsrf ? window.zmGetCsrf() : '');
  let state = null;

  function fmtDateTime(sec){
    if(!sec) return '—';
    const d = new Date(sec * 1000);
    const p2 = n => String(n).padStart(2, '0');
    return `${p2(d.getDate())}.${p2(d.getMonth()+1)}.${d.getFullYear()} ${p2(d.getHours())}:${p2(d.getMinutes())}`;
  }

  function render(){
    const cfg = (state && state.config) || {};
    const active = !!(state && state.active);
    el('maintEnabled').checked = !!cfg.enabled;
    el('maintMessage').value = cfg.message || '';
    const card = el('maintenanceView').querySelector('.tr-card');
    if(card) card.style.borderColor = active ? '#d0573f' : 'var(--line)';
    el('maintMeta').textContent = active ? 'Przerwa WŁĄCZONA' : 'Przerwa wyłączona';
    let extra = '';
    if(active && cfg.since) extra = ` · od ${fmtDateTime(cfg.since)}${cfg.by ? ' (włączył: ' + cfg.by + ')' : ''}`;
    el('maintStatus').textContent = active
      ? `Strona jest teraz niedostępna dla odwiedzających.${extra}`
      : 'Strona działa normalnie dla wszystkich.';
  }

  async function load(){
    el('maintMeta').textContent = 'Wczytywanie…';
    let d = null;
    try{
      const res = await fetch(`${API}?action=maintenance_status`, { credentials:'include' });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return; }
      d = await res.json();
    }catch(_){ d = null; }
    if(!d || !d.ok){ el('maintMeta').textContent = 'Nie udało się pobrać stanu.'; return; }
    state = d;
    render();
  }

  async function post(payload){
    try{
      const res = await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ csrf: csrf(), ...payload })
      });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const data = await res.json();
      if(!data.ok){ toast((data && data.message) || 'Nie udało się zapisać.'); return null; }
      return data;
    }catch(_){ toast('Błąd połączenia'); return null; }
  }

  el('maintRefreshBtn').addEventListener('click', load);

  el('maintSaveBtn').addEventListener('click', async () => {
    const enabled = el('maintEnabled').checked;
     const d = await post({ action:'maintenance_save', enabled, message: el('maintMessage').value });
    if(!d) return;
    state = d;
    render();
    toast(d.active ? 'Przerwa techniczna włączona.' : 'Przerwa techniczna wyłączona.');
  });

  window.zmMaintenance = { refresh(){ load(); } };
})();
</script>
<script>
/* ------------------------------------------------------------------
   Atrakcje — moduł panelu.
   Edytuje konfigurację systemu atrakcji: listę etykiet (nazwa, domyślne
   zaznaczenie), tytuł i podtytuł sekcji, liczbę zdjęć, tryb wyboru,
   interwał karuzeli oraz przełączniki widoczności i zgłaszania.
   Zapis przez attraction_save; odczyt przez attraction_config.
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const el = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const toast = m => (window.zmToast ? window.zmToast(m) : null);
  const csrf = () => (window.zmGetCsrf ? window.zmGetCsrf() : '');
  const num = n => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('pl-PL');

  let started = false;
  let cfg = null;
  let rows = [];

  // slug identyczny jak zm_attraction_label_key() po stronie PHP
  function slug(s){
    s = String(s || '').toLowerCase();
    const map = { 'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z' };
    s = s.replace(/[ąćęłńóśźż]/g, c => map[c] || c);
    return s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
  }

  function renderLabels(){
    const box = el('attrLabels');
    if(!rows.length){
      box.innerHTML = '<div class="attr-empty">Brak etykiet — dodaj pierwszą poniżej.</div>';
      return;
    }
    box.innerHTML = rows.map((r, i) => {
      const key = r.key || slug(r.name);
      return `<div class="attr-row" data-i="${i}">
        <input class="attr-name" type="text" value="${esc(r.name)}" maxlength="60" placeholder="Nazwa etykiety">
        <input class="attr-icon" type="text" value="${esc(r.icon || '')}" maxlength="12" placeholder="ikona 🏛️" title="Ikonka (emoji) pokazywana przy kategorii" style="max-width:90px;">
        <input class="attr-desc" type="text" value="${esc(r.desc || '')}" maxlength="120" placeholder="Krótki opis…" title="Krótki opis kategorii" style="flex:1;min-width:140px;">
        <label class="sec-toggle"><input type="checkbox" class="attr-default"${r.default ? ' checked' : ''}> domyślne</label>
        <span class="attr-key">klucz: <code>${esc(key || '—')}</code> · użyć: ${num(r.used || 0)}</span>
        <button type="button" class="tool-btn mini danger" data-remove>Usuń</button>
      </div>`;
    }).join('');
  }

  function adopt(d){
    cfg = d.config || {};
    rows = (cfg.labels || []).map(l => ({ key: l.key || '', name: l.name || '', icon: l.icon || '', desc: l.desc || '', default: !!l.default, used: l.used || 0 }));

    el('attrTitle').value = cfg.section_title || '';
    el('attrSubtitle').value = cfg.section_subtitle || '';
    el('attrCount').value = cfg.count;
    el('attrMode').value = cfg.mode;
    el('attrAutoplay').value = cfg.autoplay_seconds;
    el('attrMaxLabels').value = (cfg.max_labels === undefined || cfg.max_labels === null) ? 2 : cfg.max_labels;
    el('attrShowHome').checked = !!cfg.show_on_home;
    el('attrEnableSubmission').checked = !!cfg.enable_submission;
    el('attrBonusPoints').value = (cfg.bonus_points === undefined || cfg.bonus_points === null) ? 0 : cfg.bonus_points;
    el('attrNotifyAttraction').checked = !!cfg.notify_attraction;

    if(el('attrMeta')) el('attrMeta').textContent = `${num(d.labeled || 0)} opublikowanych zdjęć z etykietą`;
    renderLabels();
  }

  function collectLabels(){
    const out = [];
    const seen = {};
    rows.forEach(r => {
      const name = (r.name || '').trim();
      let key = r.key || slug(name);
      if(!key){ return; }
      if(seen[key]){ return; }
      seen[key] = true;
      out.push({ key: key, name: name || key, icon: (r.icon || '').trim(), desc: (r.desc || '').trim(), default: !!r.default });
    });
    return out;
  }

  async function load(){
    const note = el('attrNote');
    if(note) note.textContent = 'Wczytywanie…';
    let d = null;
    try{
      const res = await fetch(`${API}?action=attraction_config`, { credentials:'include' });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return; }
      d = await res.json();
    }catch(_){ d = null; }

    if(!d || !d.ok){
      if(note) note.textContent = 'Nie udało się pobrać konfiguracji atrakcji.';
      return;
    }
    adopt(d);
    if(note) note.textContent = '';
  }

  async function post(payload){
    try{
      const res = await fetch(API, {
        method:'POST', credentials:'include',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ csrf: csrf(), ...payload })
      });
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      const data = await res.json();
      if(!data.ok){ toast((data && data.message) || 'Nie udało się zapisać konfiguracji.'); return null; }
      return data;
    }catch(_){
      toast('Błąd połączenia');
      return null;
    }
  }

  el('attrRefreshBtn').addEventListener('click', load);

  el('attrSaveBtn').addEventListener('click', async () => {
    const d = await post({
      action:'attraction_save',
      labels: collectLabels(),
      section_title: el('attrTitle').value.trim(),
      section_subtitle: el('attrSubtitle').value.trim(),
      count: parseInt(el('attrCount').value, 10) || 0,
      mode: el('attrMode').value,
      autoplay_seconds: parseInt(el('attrAutoplay').value, 10) || 0,
      max_labels: parseInt(el('attrMaxLabels').value, 10) || 2,
      show_on_home: el('attrShowHome').checked,
      enable_submission: el('attrEnableSubmission').checked,
      bonus_points: parseInt(el('attrBonusPoints').value, 10) || 0,
      notify_attraction: el('attrNotifyAttraction').checked,
    });
    if(!d) return;
    toast('Konfiguracja atrakcji zapisana.');
    load();
  });

  function addLabel(){
    const inp = el('attrNewLabel');
    const name = inp.value.trim();
    if(!name) return;
    if(!slug(name)){ toast('Nazwa musi zawierać literę lub cyfrę.'); return; }
    rows.push({ key:'', name: name, default:false, used:0 });
    inp.value = '';
    renderLabels();
    const names = el('attrLabels').querySelectorAll('.attr-name');
    if(names.length) names[names.length - 1].focus();
  }

  el('attrAddLabelBtn').addEventListener('click', addLabel);
  el('attrNewLabel').addEventListener('keydown', ev => {
    if(ev.key === 'Enter'){ ev.preventDefault(); addLabel(); }
  });

  el('attrLabels').addEventListener('input', ev => {
    const row = ev.target.closest('.attr-row');
    if(!row) return;
    const i = +row.dataset.i;
    if(!rows[i]) return;
    if(ev.target.classList.contains('attr-name')){
      rows[i].name = ev.target.value;
      const code = row.querySelector('.attr-key code');
      if(code) code.textContent = rows[i].key || slug(rows[i].name) || '—';
    } else if(ev.target.classList.contains('attr-icon')){
      rows[i].icon = ev.target.value;
    } else if(ev.target.classList.contains('attr-desc')){
      rows[i].desc = ev.target.value;
    }
  });

  el('attrLabels').addEventListener('change', ev => {
    const row = ev.target.closest('.attr-row');
    if(!row) return;
    const i = +row.dataset.i;
    if(rows[i] && ev.target.classList.contains('attr-default')){
      rows[i].default = ev.target.checked;
    }
  });

  el('attrLabels').addEventListener('click', ev => {
    const btn = ev.target.closest('[data-remove]');
    if(!btn) return;
    const row = ev.target.closest('.attr-row');
    if(!row) return;
    rows.splice(+row.dataset.i, 1);
    renderLabels();
  });

  window.zmAttraction = {
    start(){ if(started) return; started = true; load(); },
    refresh(){ started = true; load(); },
    stop(){ started = false; },
  };
})();
</script>
<script>
/* ===== Ustawienia — SCHEMAT (bez funkcji zapisu) =====
 * Dane poniżej opisują przyszłe ustawienia. Gdy dana funkcja zostanie
 * zaimplementowana: zmień s:"plan" na s:"exists" i dodaj pole go:"<widok>".
 * s: "plan" = planowane, "hot" = zalecane na start, "exists" = już działa w innej zakładce */
(function(){
  const $ = id => document.getElementById(id);
  const GO = { comments:'tabComments', photos:'tabPhotos', projects:'tabProjects', attraction:'tabAttraction', users:'tabUsers', traffic:'tabTraffic', security:'tabSecurity', maintenance:'tabMaintenance', reports:'reportsBtn', notify:'notifyBtn', words:'wordsBtn', blocked:'blockedBtn' };
  const i = (n, d, s, go) => ({ n, d, s: s || 'plan', go });

  const SECTIONS = [
    { id:'ideas', title:'Pomysły do analizy',
      intro:'Wnioski z przeglądu kodu strony i propozycje, co ułatwiłoby zarządzanie nią. Kolejność w grupach = proponowany priorytet.',
      groups:[
        { h:'Znalezione w kodzie (SEO)', items:[
          i('Pojedyncze zdjęcie nie ma własnego adresu w Google', 'index.php ustawia canonical na stronę główną, gdy w adresie jest ?photo=. Zdjęcia nie mogą więc być osobnymi wynikami wyszukiwania. Rozwiązanie: przyjazny adres ze slugiem (np. /zdjecie/p12-rynek-mogilno) z własnym title, description i og:image. Wymaga kolumny slug w tabeli photos oraz przekierowań 301.', 'hot'),
          i('Udostępniony link pokazuje tylko małą ikonę', 'og:image to zawsze icon-512.png (kwadrat 512 px), a twitter:card to „summary”. Messenger i Facebook nie pokażą zdjęcia. Docelowo: og:image = zdjęcie (photo.php?id=…&s=1200) i karta summary_large_image, a dla strony głównej osobny obraz 1200×630.', 'hot'),
          i('Domena wpisana na sztywno', 'Adres https://zmogilna.pl jest zapisany bezpośrednio w index.php i funkcje.php, a profil.php używa zm_base_url(). Jedno ustawienie „Adres strony” uporządkuje canonical, og:url i sitemapę oraz ułatwi testy na innej domenie.', 'hot'),
          i('Canonical w funkcje.php przyjmuje parametry z adresu', 'Strona używa pełnego REQUEST_URI, więc ta sama treść może mieć kilka „kanonicznych” adresów. Warto zastosować ten sam filtr parametrów co w index.php.'),
          i('Brak generatora sitemap.xml w przesłanych plikach', 'Jeśli mapa strony jest ręczna, warto ją generować z bazy (zdjęcia, kategorie, publiczne profile) razem z rozszerzeniem image-sitemap.'),
        ]},
        { h:'Warto dodać, żeby łatwiej zarządzać stroną', items:[
          i('Lista braków w treści', 'Jeden raport: zdjęcia bez opisu, miejscowości, kategorii lub z za krótkim tytułem, duplikaty tytułów, kategorie bez opisu. Z przyciskiem „Przejdź do edycji”. To najszybszy sposób na poprawę SEO bez pisania nowych funkcji.', 'hot'),
          i('Tekst alternatywny (alt) i opis SEO zdjęcia', 'Tabela photos ma dziś title, description i place, ale nie ma osobnego alt ani opisu SEO. Alt można generować automatycznie („tytuł — miejscowość”) z możliwością ręcznej poprawki.', 'hot'),
          i('Dziennik zmian ustawień', 'Kto, kiedy i co zmienił, z możliwością cofnięcia. W bazie jest już tabela admin_log, którą można wykorzystać.'),
          i('Eksport i import konfiguracji (JSON)', 'Kopia ustawień jednym kliknięciem przed większą zmianą oraz przeniesienie ich na inne środowisko.'),
          i('Kopia zapasowa jednym przyciskiem', 'Baza + katalog uploads/ jako archiwum do pobrania, z przypomnieniem, gdy ostatnia kopia jest starsza niż X dni.'),
          i('Test wysyłki e-mail', 'Przycisk „Wyślij wiadomość testową” przy konfiguracji nadawcy — od razu widać, czy działają resety haseł i powiadomienia.'),
          i('Wyczyść i odbuduj cache miniatur', 'Przydatne po zmianie jakości lub rozmiarów miniatur albo po podmianie zdjęcia (photo.php trzyma miniatury w cache).'),
          i('Zgodność z RODO', 'Treść bannera cookies, okres przechowywania logów, eksport i usunięcie danych konta na żądanie użytkownika.'),
        ]},
      ]},

    { id:'seo', title:'SEO i metadane',
      intro:'Docelowo cała kontrola nad tym, co widzi Google i co pokazują podglądy linków (Facebook, Messenger, X) — bez edycji plików. Teraz metadane są wpisane w kodzie stron.',
      groups:[
        { h:'Metadane globalne', items:[
          i('Adres strony (domena bazowa)', 'Jedna wartość używana w canonical, og:url, sitemapie i mailach. Dziś wpisana na sztywno w kilku plikach.', 'hot'),
          i('Nazwa strony i slogan', 'Używane w tytułach, og:site_name i danych strukturalnych.'),
          i('Szablon tytułu strony', 'Np. „{tytuł} — {miejscowość} | zMogilna.pl”. Zalecana długość do ok. 60 znaków.'),
          i('Domyślny opis (meta description)', 'Dziś zapisany w index.php. Zalecana długość do ok. 160 znaków; licznik znaków przy polu.'),
          i('Domyślny obraz udostępniania', 'Obraz 1200×630 px zamiast icon-512.png. Z podglądem, jak będzie wyglądał na Facebooku i w Messengerze.', 'hot'),
          i('Typ karty X / Twitter', 'summary albo summary_large_image (duży obraz).'),
          i('Język i locale', 'lang strony i og:locale (obecnie pl_PL).'),
          i('Kolor motywu przeglądarki i ikony', 'theme-color, favicon.svg, favicon-32.png i icon-512.png w jednym miejscu.'),
        ]},
        { h:'Metadane poszczególnych podstron', items:[
          i('Strona główna', 'Title, description, og:image i robots. Wartości dziś są w index.php.'),
          i('Strona „Funkcje”', 'Własny title i description, poprawiony canonical.'),
          i('Profile publiczne', 'Szablon: „{nazwa} — profil na zMogilna.pl”, opcja noindex dla profili bez zdjęć.'),
          i('Strony prywatne (panel, logowanie, profil edycji)', 'Zawsze noindex, nofollow — z możliwością podglądu listy takich stron.'),
        ]},
        { h:'Metadane zdjęć, kategorii i miejscowości', items:[
          i('Przyjazny adres (slug)', 'Automatycznie z tytułu i miejscowości, z możliwością ręcznej zmiany; stary adres przekierowuje 301.', 'hot'),
          i('Tytuł SEO i opis SEO zdjęcia', 'Nadpisują automatyczny szablon, gdy zdjęcie jest ważne (np. atrakcja turystyczna).'),
          i('Tekst alternatywny (alt)', 'Pole osobne od tytułu; opcja automatycznego uzupełniania dla zdjęć bez alt.', 'hot'),
          i('Opis kategorii jako meta description', 'Tabela categories ma już pole description (255 znaków) — wystarczy je podpiąć.'),
          i('Noindex pojedynczego zdjęcia lub kategorii', 'Dla zdjęć słabej jakości albo prywatnych.'),
        ]},
        { h:'Indeksowanie', items:[
          i('Sitemap.xml generowana automatycznie', 'Zdjęcia, kategorie, publiczne profile; rozszerzenie dla obrazów; data modyfikacji z updated_at.', 'hot'),
          i('Edytor robots.txt', 'Z podglądem i wskazaniem sitemapy; ostrzeżenie przed zablokowaniem całej strony.'),
          i('Przekierowania 301', 'Lista „stary adres → nowy adres”, tworzona automatycznie przy zmianie sluga.'),
          i('Strona 404 i komunikat błędu', 'Edytowalny tekst i propozycje popularnych zdjęć.'),
        ]},
        { h:'Dane strukturalne (JSON-LD)', items:[
          i('WebSite i Organization', 'Dziś blok JSON-LD jest wpisany w index.php. Docelowo generowany z ustawień globalnych.'),
          i('ImageObject / Photograph dla zdjęcia', 'Autor, data wykonania, miejsce, współrzędne — dane już są w tabeli photos.'),
          i('BreadcrumbList', 'Ścieżka: strona główna → kategoria → zdjęcie.'),
        ]},
        { h:'Weryfikacja i analityka', items:[
          i('Kody weryfikacyjne', 'Google Search Console i Bing Webmaster jako znaczniki meta.'),
          i('Identyfikator analityki', 'Opcjonalnie Plausible, Matomo lub GA — wstrzykiwany tylko po zgodzie na cookies.'),
          i('Statystyki: SEO i źródła', 'Raport ruchu z wyszukiwarek już istnieje.', 'exists', 'traffic'),
        ]},
        { h:'Narzędzia kontrolne', items:[
          i('Podgląd wyniku Google i karty Facebooka', 'Symulacja wyglądu przed zapisem.'),
          i('Liczniki długości', 'Title do 60 i description do 160 znaków, z kolorową wskazówką.'),
          i('Audyt SEO', 'Raport: brak opisów, duplikaty tytułów, zdjęcia bez alt, strony bez canonical.'),
        ]},
      ]},

    { id:'texts', title:'Edycja tekstów na stronie',
      intro:'Edytor tekstów strony głównej jest u góry. Zapisana zmiana jest widoczna na stronie po jej odświeżeniu. Pole puste albo równe tekstowi domyślnemu oznacza „użyj domyślnego”. Teksty są zwykłym tekstem — HTML nie jest wykonywany. Poniżej: co edytor już obejmuje i co jest dopiero planowane.',
      groups:[
        { h:'Co już działa', items:[
          i('Teksty strony głównej', 'Nagłówek, hasło, liczniki, przyciski gier, stopka oraz tytuł i opis dla wyszukiwarek (title, meta description, og:title, og:description).', 'exists'),
          i('Historia zmian i przywracanie', 'Ostatnie 20 wersji każdego tekstu: kto, kiedy i co. Przycisk „Użyj” wstawia starą wersję do pola, a zapisujesz ją sam.', 'exists'),
          i('Przywróć domyślny', 'Jeden przycisk przy każdym tekście; tekst domyślny jest zapisany w kodzie, więc strona działa także przy pustej bazie.', 'exists'),
        ]},
        { h:'Jeszcze nieobjęte (planowane)', items:[
          i('Komunikaty wyświetlane przez JavaScript', 'Np. „Ten folder jest pusty”, „Brak wyników” — wymagają przekazania tekstów do skryptu strony.'),
          i('Sekcja społeczności i okno kontaktu', 'Leżą w osobnych plikach (community-section.php) — do podpięcia pod ten sam mechanizm.'),
          i('Strona „Funkcje”', 'Własne teksty i metadane tej podstrony.'),
          i('Strony informacyjne', 'O stronie, regulamin, polityka prywatności i cookies, zasady dodawania zdjęć, kontakt.'),
          i('Wiadomości e-mail', 'E-mail powitalny i resetu hasła.'),
          i('Sekcja „Atrakcje”', 'Tytuł i podtytuł są już edytowalne w zakładce Atrakcje.', 'exists', 'attraction'),
          i('Komunikat przerwy technicznej', 'Edytowalny w zakładce Przerwa techniczna.', 'exists', 'maintenance'),
        ]},
      ]},

    { id:'site', title:'Strona i wygląd',
      intro:'Tożsamość strony i elementy widoczne dla odwiedzających.',
      groups:[
        { h:'Tożsamość', items:[
          i('Logo i nazwa w nagłówku', 'Obraz lub tekst, z podglądem w obu motywach.'),
          i('E-mail kontaktowy i linki społecznościowe', 'Wyświetlane w stopce i danych strukturalnych.'),
          i('Domyślny motyw', 'Jasny, ciemny lub zgodny z systemem. Wybór użytkownika dalej zapamiętywany w przeglądarce.'),
          i('Kolor akcentu', 'Dziś złoty (#c9a227) — jedna zmienna dla całej strony.'),
        ]},
        { h:'Moduły na stronie głównej', items:[
          i('Włączanie i wyłączanie modułów', 'Atrakcje, ulubione, gra, mapa — z kolejnością wyświetlania.'),
          i('Sekcja Atrakcje', 'Tytuł, liczba zdjęć, tryb i karuzela są już konfigurowalne.', 'exists', 'attraction'),
          i('Zdjęcie dnia', 'Ręczny wybór lub losowanie, z opcją wyróżnienia na stronie głównej.'),
        ]},
      ]},

    { id:'photos', title:'Zdjęcia i treści',
      intro:'Reguły dodawania, przetwarzania i publikacji zdjęć.',
      groups:[
        { h:'Dodawanie', items:[
          i('Limity przesyłania', 'Maksymalny rozmiar pliku, minimalne i maksymalne wymiary, dozwolone formaty.'),
          i('Tryb zgłoszeń', 'Zawsze moderacja ręczna albo automatyczne zatwierdzanie zaufanych autorów.'),
          i('Domyślna kategoria', 'Dla zdjęć bez wskazanej kategorii.'),
          i('Planowana publikacja', 'Mechanizm już działa na stronie; w panelu przydałby się domyślny odstęp i godziny publikacji.'),
        ]},
        { h:'Przetwarzanie', items:[
          i('Jakość miniatur i format WebP', 'Rozmiary miniatur oraz jakość JPEG/WebP używane przez photo.php.'),
          i('Cache miniatur', 'Przycisk „Wyczyść i odbuduj” oraz podgląd zajętego miejsca.', 'hot'),
          i('Znak wodny', 'Opcjonalny, z pozycją i przezroczystością.'),
          i('Sortowanie domyślne galerii', 'Najnowsze, losowo lub ręczna kolejność.'),
        ]},
        { h:'Istniejące narzędzia', items:[
          i('Zarządzanie zdjęciami i kategoriami', 'Dodawanie, edycja, skanowanie katalogu.', 'exists', 'photos'),
          i('Etykiety atrakcji', 'Etykiety, bonus punktów i powiadomienia.', 'exists', 'attraction'),
        ]},
      ]},

    { id:'accounts', title:'Konta i moderacja',
      intro:'Zasady dla użytkowników, komentarzy i zgłoszeń.',
      groups:[
        { h:'Rejestracja i konta', items:[
          i('Włączenie rejestracji', 'Przełącznik otwarte/zamknięte, opcjonalnie z weryfikacją e-mail.'),
          i('Domyślna widoczność profilu', 'Publiczny lub prywatny po utworzeniu konta.'),
          i('Eksport i usunięcie danych konta', 'Realizacja żądań RODO bez ręcznej pracy.'),
          i('Rangi, punkty i bany', 'Zarządzanie użytkownikami.', 'exists', 'users'),
        ]},
        { h:'Komentarze', items:[
          i('Włączenie komentarzy', 'Globalnie oraz per zdjęcie.'),
          i('Moderacja wstępna', 'Komentarze nowych użytkowników czekają na akceptację.'),
          i('Limity', 'Maksymalna długość, odstęp między komentarzami, dozwolone linki.'),
          i('Wulgaryzmy', 'Lista blokowanych wyrazów.', 'exists', 'words'),
          i('Zablokowane nazwy', 'Loginy, których nie wolno użyć.', 'exists', 'blocked'),
          i('Zgłoszenia od czytelników', 'Obsługa zgłoszeń komentarzy i zdjęć.', 'exists', 'reports'),
        ]},
      ]},

    { id:'system', title:'System i dane',
      intro:'Utrzymanie, bezpieczeństwo i kopie zapasowe.',
      groups:[
        { h:'Utrzymanie', items:[
          i('Kopia zapasowa', 'Baza i katalog uploads/ jako archiwum; przypomnienie o starej kopii.', 'hot'),
          i('Eksport i import konfiguracji', 'Plik JSON z wszystkimi ustawieniami.'),
          i('Dziennik zmian ustawień', 'Kto, kiedy i co zmienił.'),
          i('Strefa czasowa i format dat', 'Spójne wyświetlanie dat na stronie i w panelu.'),
        ]},
        { h:'E-mail', items:[
          i('Nadawca i serwer SMTP', 'Adres „od”, dane serwera, test połączenia.'),
          i('Wiadomość testowa', 'Przycisk sprawdzający dostarczalność.'),
        ]},
        { h:'Istniejące narzędzia', items:[
          i('Przerwa techniczna', 'Blokada strony z oknem logowania administratora.', 'exists', 'maintenance'),
          i('Bezpieczeństwo', 'HTTPS, nagłówki, bany IP, dziennik zdarzeń, audyt środowiska.', 'exists', 'security'),
          i('Statystyki ruchu', 'Odsłony, źródła, urządzenia, stan techniczny.', 'exists', 'traffic'),
        ]},
      ]},
  ];

  const LABEL = { plan:'planowane', hot:'zalecane na start', exists:'już działa' };
  const nav = $('setSubtabs'), body = $('setBody');
  if(!nav || !body) return;

  function h(tag, cls, text){
    const e = document.createElement(tag);
    if(cls) e.className = cls;
    if(text != null) e.textContent = text;
    return e;
  }

  function show(id){
    const sec = SECTIONS.find(s => s.id === id) || SECTIONS[0];
    nav.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.sec === sec.id));
    body.textContent = '';
    body.appendChild(h('p', 'set-intro', sec.intro));
    if(sec.id === 'texts' && window.zmTextsEditor) window.zmTextsEditor.mount(body);
    const grid = h('div', 'tr-grid');
    sec.groups.forEach(g => {
      const card = h('div', 'tr-card');
      card.appendChild(h('h3', null, g.h));
      g.items.forEach(it => {
        const row = h('div', 'set-item');
        const top = h('div', 'set-item-top');
        top.appendChild(h('b', null, it.n));
        top.appendChild(h('span', 'set-badge set-' + it.s, LABEL[it.s] || it.s));
        row.appendChild(top);
        row.appendChild(h('div', 'set-desc', it.d));
        if(it.go && GO[it.go]){
          const b = h('button', 'tool-btn mini', 'Otwórz →');
          b.type = 'button';
          b.addEventListener('click', () => { const t = $(GO[it.go]); if(t) t.click(); });
          row.appendChild(b);
        }
        card.appendChild(row);
      });
      grid.appendChild(card);
    });
    body.appendChild(grid);
  }

  SECTIONS.forEach(s => {
    const b = h('button', 'tool-btn', s.title);
    b.type = 'button';
    b.dataset.sec = s.id;
    b.addEventListener('click', () => show(s.id));
    nav.appendChild(b);
  });

  let ready = false;
  window.zmSettings = { refresh(){ if(!ready){ ready = true; show(SECTIONS[0].id); } } };
})();
</script>

<script>
/* ------------------------------------------------------------------
   Edytor tekstów strony głównej (Ustawienia → Edycja tekstów na stronie).
   Odczyt: texts_list, historia: texts_history, zapis: texts_save.
   Wszystkie teksty trafiają do DOM przez textContent / value — nigdy innerHTML.
   ------------------------------------------------------------------ */
(function(){
  const API = 'admin-api.php';
  const csrf = () => (window.zmGetCsrf ? window.zmGetCsrf() : '');
  const toast = m => (window.zmToast ? window.zmToast(m) : null);

  let root = null, bar = null, saveBtn = null, discardBtn = null, info = null, listBox = null;
  let data = null;               // ostatnia odpowiedź serwera
  let fields = {};               // key -> { item, input, wrap, count, err, flag, histBox }
  let loading = false;

  function h(tag, cls, text){
    const e = document.createElement(tag);
    if(cls) e.className = cls;
    if(text != null) e.textContent = text;
    return e;
  }

  function fmt(sec){
    if(!sec) return '—';
    const d = new Date(sec * 1000), p = n => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
  }

  async function call(url, opts){
    try{
      const res = await fetch(url, Object.assign({ credentials:'include' }, opts || {}));
      if(res.status === 401){ window.zmSessionLost && window.zmSessionLost(); return null; }
      let d = null;
      try{ d = await res.json(); }catch(_){ d = null; }
      return d || { error:'bad_response' };
    }catch(_){
      return { error:'network' };
    }
  }

  function dirtyKeys(){
    return Object.keys(fields).filter(k => fields[k].input.value !== fields[k].item.value);
  }

  function refreshField(k){
    const f = fields[k];
    const len = Array.from(f.input.value).length;
    const max = f.item.max, rec = f.item.rec;
    f.count.textContent = rec ? `${len} / zalecane ${rec} (maks. ${max})` : `${len} / ${max}`;
    f.count.classList.toggle('over', (rec && len > rec) || len > max);
    f.wrap.classList.toggle('dirty', f.input.value !== f.item.value);
    const isDefault = f.input.value === f.item.default;
    f.flag.textContent = f.input.value !== f.item.value ? 'niezapisane'
                       : (f.item.custom ? 'zmieniony' : 'domyślny');
    f.flag.className = 'tx-flag ' + (f.input.value !== f.item.value ? 'tx-unsaved' : (f.item.custom ? 'tx-custom' : ''));
    f.resetBtn.disabled = isDefault;
    f.err.textContent = '';
  }

  function refreshBar(){
    const n = dirtyKeys().length;
    saveBtn.disabled = n === 0;
    discardBtn.disabled = n === 0;
    saveBtn.textContent = n ? `Zapisz zmiany (${n})` : 'Zapisz zmiany';
    info.textContent = n ? `Niezapisane zmiany: ${n}` : 'Brak niezapisanych zmian.';
  }

  async function toggleHistory(k){
    const f = fields[k];
    if(f.histBox.dataset.open === '1'){ f.histBox.dataset.open = '0'; f.histBox.textContent = ''; return; }
    f.histBox.dataset.open = '1';
    f.histBox.textContent = 'Wczytywanie…';
    const d = await call(`${API}?action=texts_history&key=${encodeURIComponent(k)}`);
    f.histBox.textContent = '';
    if(!d || !d.ok){ f.histBox.textContent = 'Nie udało się pobrać historii.'; return; }
    if(!d.history.length){ f.histBox.textContent = 'Ten tekst nie był jeszcze zmieniany.'; return; }
    d.history.forEach(row => {
      const line = h('div', 'tx-hist-row');
      const val = row.value === null ? f.item.default : row.value;
      line.appendChild(h('span', 'tx-hist-meta', `${fmt(row.changed_at)} · ${row.changed_by || '—'}${row.value === null ? ' · powrót do domyślnego' : ''}`));
      line.appendChild(h('span', 'tx-hist-val', val));
      const use = h('button', 'tool-btn mini', 'Użyj');
      use.type = 'button';
      use.addEventListener('click', () => { f.input.value = val; refreshField(k); refreshBar(); f.input.focus(); });
      line.appendChild(use);
      f.histBox.appendChild(line);
    });
  }

  function buildField(item){
    const wrap = h('div', 'tx-field');
    const top = h('div', 'tx-top');
    top.appendChild(h('b', null, item.label));
    const flag = h('span', 'tx-flag');
    top.appendChild(flag);
    wrap.appendChild(top);
    if(item.hint) wrap.appendChild(h('div', 'tx-hint', item.hint));

    const input = item.multiline ? document.createElement('textarea') : document.createElement('input');
    if(item.multiline) input.rows = 3; else input.type = 'text';
    input.value = item.value;
    input.maxLength = item.max + 200;      // twardy limit sprawdza serwer; tu tylko bufor, a licznik ostrzega
    input.spellcheck = true;
    input.setAttribute('aria-label', item.label);
    wrap.appendChild(input);

    const meta = h('div', 'tx-meta');
    const count = h('span', 'tx-count');
    meta.appendChild(count);
    if(item.custom) meta.appendChild(h('span', null, `zapisał: ${item.updated_by || '—'}, ${fmt(item.updated_at)}`));
    wrap.appendChild(meta);

    const defLine = h('div', 'tx-default');
    defLine.appendChild(h('span', null, 'Domyślnie: '));
    defLine.appendChild(h('i', null, item.default));
    wrap.appendChild(defLine);

    const err = h('div', 'tx-err');
    wrap.appendChild(err);

    const actions = h('div', 'tx-actions');
    const resetBtn = h('button', 'tool-btn mini', 'Przywróć domyślny');
    resetBtn.type = 'button';
    const histBtn = h('button', 'tool-btn mini', 'Historia');
    histBtn.type = 'button';
    actions.appendChild(resetBtn); actions.appendChild(histBtn);
    wrap.appendChild(actions);
    const histBox = h('div', 'tx-hist');
    histBox.dataset.open = '0';
    wrap.appendChild(histBox);

    const f = { item, input, wrap, count, err, flag, resetBtn, histBox };
    fields[item.key] = f;
    input.addEventListener('input', () => { refreshField(item.key); refreshBar(); });
    resetBtn.addEventListener('click', () => { input.value = item.default; refreshField(item.key); refreshBar(); });
    histBtn.addEventListener('click', () => toggleHistory(item.key));
    refreshField(item.key);
    return wrap;
  }

  function build(){
    fields = {};
    listBox.textContent = '';
    const grid = h('div', 'tx-grid');
    data.groups.forEach(g => {
      const card = h('div', 'tr-card');
      card.appendChild(h('h3', null, g));
      data.texts.filter(t => t.group === g).forEach(t => card.appendChild(buildField(t)));
      grid.appendChild(card);
    });
    listBox.appendChild(grid);
    refreshBar();
  }

  async function load(force){
    if(loading) return;
    if(!force && dirtyKeys().length) return;       // nie nadpisujemy rozpoczętej edycji
    loading = true;
    info.textContent = 'Wczytywanie…';
    const d = await call(`${API}?action=texts_list`);
    loading = false;
    if(!d || !d.ok){ info.textContent = 'Nie udało się wczytać tekstów.'; return; }
    data = d;
    build();
  }

  async function save(){
    const keys = dirtyKeys();
    if(!keys.length) return;
    const changes = {};
    keys.forEach(k => { changes[k] = fields[k].input.value; });
    saveBtn.disabled = true;
    info.textContent = 'Zapisywanie…';
    const d = await call(API, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ csrf: csrf(), action:'texts_save', changes })
    });
    if(d && d.ok){
      data = d;
      build();
      toast(d.changed.length ? `Zapisano zmiany: ${d.changed.length}` : 'Nic się nie zmieniło');
      return;
    }
    if(d && d.error === 'invalid_texts' && d.fields){
      Object.keys(d.fields).forEach(k => { if(fields[k]) fields[k].err.textContent = d.fields[k]; });
      toast('Popraw zaznaczone pola — nic nie zostało zapisane.');
    }else{
      toast(d && d.error === 'bad_csrf' ? 'Sesja wygasła — odśwież panel.' : 'Nie udało się zapisać.');
    }
    refreshBar();
  }

  function ensureRoot(){
    if(root) return;
    root = h('div', 'tx-root');
    bar = h('div', 'filters-row');
    saveBtn = h('button', 'tool-btn', 'Zapisz zmiany'); saveBtn.type = 'button'; saveBtn.disabled = true;
    discardBtn = h('button', 'tool-btn', 'Odrzuć zmiany'); discardBtn.type = 'button'; discardBtn.disabled = true;
    const view = h('a', 'tool-btn', 'Zobacz stronę ↗'); view.href = 'index.php'; view.target = '_blank'; view.rel = 'noopener';
    info = h('span', 'admin-count-line', ''); info.style.margin = '0'; info.style.alignSelf = 'center';
    bar.appendChild(saveBtn); bar.appendChild(discardBtn); bar.appendChild(view); bar.appendChild(info);
    listBox = h('div', 'tx-list');
    root.appendChild(bar); root.appendChild(listBox);

    saveBtn.addEventListener('click', save);
    discardBtn.addEventListener('click', () => { dirtyKeys().forEach(k => { fields[k].input.value = fields[k].item.value; refreshField(k); }); refreshBar(); });
    window.addEventListener('beforeunload', ev => { if(root && dirtyKeys().length){ ev.preventDefault(); ev.returnValue = ''; } });
  }

  window.zmTextsEditor = {
    mount(container){
      ensureRoot();
      container.appendChild(root);
      load(!data);          // pierwszy raz zawsze; później tylko gdy brak niezapisanych zmian
    }
  };
})();
</script>

<script src="zmogilna-links.js?v=3" defer></script>
</body>
</html>
