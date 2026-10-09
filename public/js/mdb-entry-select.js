// Native selects retain their form values; the popup supplies integrated search.
export function installCompactSelects(root = document) {
    const controls = [];
    root.querySelectorAll('select[data-compact-select]').forEach(select => {
        const wrapper = document.createElement('div'); wrapper.className = 'entry-combobox';
        select.before(wrapper); wrapper.append(select); select.classList.add('entry-native-select'); select.tabIndex = -1;
        const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'entry-combobox-trigger';
        trigger.setAttribute('role','combobox'); trigger.setAttribute('aria-haspopup','listbox'); trigger.setAttribute('aria-expanded','false');
        trigger.setAttribute('aria-label',select.getAttribute('aria-label') || select.name);
        const popup = document.createElement('div'); popup.className = 'entry-combobox-popup'; popup.hidden = true;
        const search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Search code or description'; search.setAttribute('aria-label','Search '+trigger.getAttribute('aria-label'));
        const list = document.createElement('div'); list.id = select.id+'-choices'; list.setAttribute('role','listbox'); trigger.setAttribute('aria-controls',list.id);
        popup.append(search,list); wrapper.append(trigger,popup);
        let options = [], position = 0;
        const close = () => { popup.hidden = true; trigger.setAttribute('aria-expanded','false'); };
        function sync() {
            const option = select.selectedOptions[0];
            trigger.textContent = !select.value ? 'Not Entered' : select.value === '__none__' ? 'None' : select.value;
            trigger.title = option?.text || ''; trigger.disabled = select.disabled;
            if(select.disabled) close();
        }
        function highlight() {
            [...list.children].forEach((el,i) => el.classList.toggle('entry-choice-active',i===position));
            const current = list.children[position];
            if(current){ search.setAttribute('aria-activedescendant',current.id); current.scrollIntoView?.({block:'nearest'}); }
        }
        function choose(index) {
            if (!options[index]) return;
            select.value = options[index].value; select.dispatchEvent(new window.Event('change',{bubbles:true})); sync(); close(); trigger.focus();
        }
        function render() {
            options = [...select.options].filter(option => !option.disabled && option.text.toLowerCase().includes(search.value.toLowerCase()));
            list.replaceChildren(); position = Math.max(0, options.findIndex(o=>o.selected));
            options.forEach((option,i) => {
                const item = document.createElement('div'); item.id = list.id+'-'+i; item.setAttribute('role','option'); item.setAttribute('aria-selected',String(option.selected));
                item.textContent = option.value && option.value !== '__none__' && !option.text.startsWith(option.value) ? option.value+' — '+option.text : option.text;
                item.addEventListener('mousedown',event=>event.preventDefault()); item.addEventListener('click',()=>choose(i)); list.append(item);
            });
            if(!options.length){const empty=document.createElement('p');empty.textContent='No matches';list.append(empty);}
            highlight();
        }
        function open() { if(select.disabled)return; controls.forEach(c=>c.close()); popup.hidden=false;trigger.setAttribute('aria-expanded','true');search.value='';render();search.focus(); }
        trigger.addEventListener('click',()=>popup.hidden ? open() : close());
        trigger.addEventListener('keydown',event=>{if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();open();}});
        search.addEventListener('input',render);
        search.addEventListener('keydown',event=>{
            if(['ArrowDown','ArrowUp','Home','End'].includes(event.key)){event.preventDefault();position=event.key==='Home' ? 0 : event.key==='End' ? options.length-1 : Math.max(0,Math.min(options.length-1,position+(event.key==='ArrowDown'?1:-1)));highlight();}
            if(event.key==='Enter'){event.preventDefault();event.stopPropagation();choose(position);}
            if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();trigger.focus();}
            if(event.key==='Tab')close();
        });
        wrapper.addEventListener('focusout',()=>queueMicrotask(()=>{if(!wrapper.contains(wrapper.ownerDocument.activeElement))close();}));
        document.addEventListener('mousedown',event=>{if(!wrapper.contains(event.target))close();});
        select.addEventListener('change',sync);
        new window.MutationObserver(sync).observe(select,{childList:true,subtree:true,attributes:true});
        controls.push({sync,close}); sync();
    });
    return () => controls.forEach(control=>control.sync());
}
