(function ($) {
    'use strict';
    $(function () {
        const rows = $('#rex-file-sort');
        if (rows.length) {
            rows.sortable({handle: '.rex-file-handle', axis: 'y'});
            rows.on('click', '.rex-file-up,.rex-file-down', function () { const row=$(this).closest('tr'); if ($(this).hasClass('rex-file-up')) row.insertBefore(row.prev()); else row.insertAfter(row.next()); });
            $('#rex-select-all').on('change', function () { $('.rex-bulk-checkbox').prop('checked', this.checked); });
            $('#rex-file-order-form').on('submit', function () { $('#rex-file-order').val(JSON.stringify(rows.children().map(function () {return Number(this.dataset.fileId);}).get())); });
            $('#rex-bulk-files-form').on('submit', function (event) { const ids=$('.rex-bulk-checkbox:checked').map(function () {return Number(this.value);}).get(); if (!ids.length) {event.preventDefault();$('#rex-bulk-status').text('Selecteer eerst bestanden.');return;} if (this.elements.bulk_action.value==='trash'&&!window.confirm(ids.length+' bestanden naar de prullenbak verplaatsen?')) {event.preventDefault();return;} $('#rex-bulk-ids').val(JSON.stringify(ids)); });
        }
        const start=document.getElementById('rex-integrity-start');
        if (start) start.addEventListener('click', async function () {
            start.disabled=true; const status=document.getElementById('rex-integrity-status'), results=document.getElementById('rex-integrity-results');results.replaceChildren();
            async function call(action, extra) {const response=await fetch(window.ajaxurl,{method:'POST',credentials:'same-origin',body:new URLSearchParams(Object.assign({action,nonce:start.dataset.nonce},extra))});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.data&&data.data.message||'Controle mislukt.');return data.data;}
            try {status.textContent='Controle voorbereiden…';const initial=await call('rex_integrity_begin',{});let batch;do {batch=await call('rex_integrity_batch',{token:initial.token});status.textContent=batch.checked+' bestanden gecontroleerd'+(batch.done?' — klaar.':'…');batch.issues.forEach(issue=>{const li=document.createElement('li');li.textContent=issue;results.appendChild(li);});} while(!batch.done);}catch(error){status.textContent=error.message;}finally{start.disabled=false;}
        });
    });
}(jQuery));
