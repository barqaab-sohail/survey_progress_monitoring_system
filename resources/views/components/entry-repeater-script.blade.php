<script>
const list=document.querySelector('[data-row-list]'),tpl=document.querySelector('#row-template'),total=document.querySelector('[data-total]');
function renumber(){[...list.children].forEach((row,i)=>{row.querySelector('[data-row-number]').textContent=`Feeder ${i+1}`;row.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/items\[\d+\]/,`items[${i}]`))});calc()}
function calc(){total.textContent=[...document.querySelectorAll('[data-quantity]')].reduce((s,x)=>s+(Number(x.value)||0),0)}
document.querySelector('[data-add-row]')?.addEventListener('click',()=>{list.append(tpl.content.cloneNode(true));renumber()});
list.addEventListener('click',e=>{if(e.target.matches('[data-remove-row]')&&list.children.length>1){e.target.closest('.row-card').remove();renumber()}});list.addEventListener('input',calc);renumber();
</script>
