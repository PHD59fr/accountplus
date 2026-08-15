<?php
/** @var array<string, mixed> $values */
$t = $t ?? static fn ($key) => $key;
?>
<?php if ($currentCompany !== null): ?>
    <p class="section-text"><?= e($t('categories.current_company_text', ['name' => $currentCompany->name()])) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
    <div class="alert"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" action="<?= e((string) ($formAction ?? '/categories')) ?>" class="ft-form ft-form-grid">
    <?= csrf_field() ?>
    <?php if ((int) ($categoryId ?? 0) > 0): ?>
        <input type="hidden" name="id" value="<?= e((string) $categoryId) ?>">
    <?php endif; ?>
    <label>
        <span><?= e($t('categories.name')) ?></span>
        <input type="text" name="name" value="<?= e((string) ($values['name'] ?? '')) ?>" required>
    </label>
    <div class="emoji-picker-field ft-form-label">
        <span><?= e($t('categories.emoji')) ?></span>
        <span class="emoji-field">
            <button type="button" class="emoji-clear-btn" title="<?= e($t('categories.emoji_clear')) ?>" hidden>✕</button>
            <button type="button" class="emoji-pick-btn" title="<?= e($t('categories.emoji_pick')) ?>"><?= e((string) ($values['emoji'] ?? '') ?: '😀') ?></button>
        </span>
        <input type="hidden" name="emoji" value="<?= e((string) ($values['emoji'] ?? '')) ?>">
        <div class="emoji-picker" hidden>
            <div class="emoji-picker-tabs"></div>
            <div class="emoji-picker-body"></div>
        </div>
    </div>
    <label>
        <span><?= e($t('categories.type')) ?></span>
        <select name="category_type">
            <option value=""><?= e($t('categories.type_select')) ?></option>
            <option value="income"<?= (($values['category_type'] ?? '') === 'income' ? ' selected' : '') ?>><?= e($t('categories.type_income')) ?></option>
            <option value="expense"<?= (($values['category_type'] ?? '') === 'expense' ? ' selected' : '') ?>><?= e($t('categories.type_expense')) ?></option>
            <option value="transfer"<?= (($values['category_type'] ?? '') === 'transfer' ? ' selected' : '') ?>><?= e($t('categories.type_transfer')) ?></option>
        </select>
    </label>
    <label>
        <span><?= e($t('categories.parent')) ?></span>
        <select name="parent_id">
            <option value=""><?= e($t('categories.parent_select')) ?></option>
            <?php foreach ($parentCategories as $parent): ?>
                <option value="<?= e((string) $parent['id']) ?>"<?= (string) ($values['parent_id'] ?? '') === (string) $parent['id'] ? ' selected' : '' ?>> <?= e((string) ($parent['emoji'] ?? '')) ?> <?= e($parent['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span><?= e($t('categories.sort_order')) ?></span>
        <input type="number" name="sort_order" min="0" value="<?= e((string) ($values['sort_order'] ?? '0')) ?>">
    </label>
    <label class="ft-form-label-checkbox span-1">
        <input type="checkbox" name="is_active" value="1"<?= (int) ($values['is_active'] ?? 1) === 1 ? ' checked' : '' ?>>
        <span><?= e($t('categories.is_active')) ?></span>
    </label>
    <button type="submit" class="ft-form-btn"><?= e($t((string) ($submitKey ?? 'categories.save'))) ?></button>
</form>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const order = ['smileys', 'gestures', 'animals', 'food', 'travel', 'activities', 'objects', 'symbols', 'flags'];
    const sections = {
        smileys: ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😋','😛','😜','🤪','😝','🤗','🤭','🤫','🤔','🤨','😐','😏','😒','🙄','😬','😥','😮','😲','😳','🥺','😢','😭','😤','😠','😡','🤬','😱','😨','😰','😓','😞','😟','😔','😕','☹️','😣','😫','🥱','😴','😷','🤒','🤕','🤢','🤮','🥴','🤠','🥳','😎','🤓','🧐','🤯','😵','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖','💖','💘','💝','💔','❤️','🧡','💛','💚','💙','💜','🖤','💋','💯'],
        gestures: ['👋','🤚','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💅','🤳','💪','🦵','🦶','👂','👃','🧠','🦷','👀','👅','👄','👶','👦','👧','🧑','👱','👨','👩','🧓','👴','👵','🙍','🙎','🙅','🙆','💁','🙋','🙇','🤦','🤷','👮','🕵️','💂','👷','🤴','👸','👳','👲','🧕','🤵','👰','🤰','🤱','👼','🎅','🤶','🦸','🦹','🧙','🧚','🧛','🧜','🧝','🧞','🧟','💆','💇','🚶','🧍','🧎','🏃','💃','🕺','👯','🧖','🧗','🤺','🏇','🏂','🏄','🚣','🏊','⛹️','🏋️','🚴','🚵','🤸','🤼','🤽','🤾','🤹','🧘','🛀','🛌','👭','👫','👬','💏','💑','👪','👣'],
        animals: ['🐵','🐒','🦍','🦧','🐶','🐕','🐩','🐺','🦊','🦝','🐱','🐈','🦁','🐯','🐅','🐆','🐴','🐎','🦄','🦓','🦌','🦬','🐮','🐂','🐃','🐄','🐷','🐖','🐗','🐽','🐏','🐑','🐐','🐪','🐫','🦙','🦒','🐘','🦏','🦛','🐭','🐁','🐀','🐹','🐰','🐇','🦔','🦇','🐻','🐨','🐼','🦥','🦦','🦨','🦘','🦡','🐾','🦃','🐔','🐓','🐣','🐤','🐥','🐦','🐧','🕊️','🦅','🦆','🦢','🦉','🦩','🦚','🦜','🐸','🐊','🐢','🦎','🐍','🐲','🐉','🦕','🦖','🐳','🐋','🐬','🦭','🐟','🐠','🐡','🦈','🐙','🐚','🐌','🦋','🐛','🐜','🐝','🐞','🦗','🕷️','🦂','🦟','💐','🌸','💮','🌹','🥀','🌺','🌻','🌼','🌷','🌱','🌲','🌳','🌴','🌵','🌾','🌿','🍀','🍁','🍂','🍃','🍄'],
        food: ['🍇','🍈','🍉','🍊','🍋','🍌','🍍','🥭','🍎','🍏','🍐','🍑','🍒','🍓','🫐','🥝','🍅','🥑','🍆','🥔','🥕','🌽','🌶️','🥒','🥬','🥦','🧄','🧅','🥜','🌰','🍞','🥐','🥖','🥨','🥯','🥞','🧇','🧀','🍖','🍗','🥩','🥓','🍔','🍟','🍕','🌭','🥪','🌮','🌯','🥙','🧆','🥚','🍳','🥘','🍲','🥗','🍿','🧈','🥫','🍱','🍘','🍙','🍚','🍛','🍜','🍝','🍠','🍢','🍣','🍤','🍥','🥮','🍡','🥟','🥠','🥡','🦀','🦞','🦐','🦑','🍦','🍧','🍨','🍩','🍪','🎂','🍰','🧁','🥧','🍫','🍬','🍭','🍮','🍯','🍼','🥛','☕','🍵','🍶','🍾','🍷','🍸','🍹','🍺','🍻','🥂','🥃','🥤','🧋','🧃','🧉','🧊','🥢','🍽️','🍴','🥄','🔪'],
        travel: ['🌍','🌎','🌏','🌐','🗺️','🗾','🧭','🏔️','⛰️','🌋','🗻','🏕️','🏖️','🏜️','🏝️','🏞️','🏟️','🏛️','🏗️','🧱','🏘️','🏠','🏡','🏢','🏣','🏤','🏥','🏦','🏨','🏩','🏪','🏫','🏬','🏭','🏯','🏰','💒','🗼','🗽','⛪','🕌','🛕','🕍','⛩️','🕋','⛲','⛺','🌁','🌃','🏙️','🌄','🌅','🌆','🌇','🌉','♨️','🎠','🎡','🎢','💈','🎪','🚂','🚃','🚄','🚅','🚆','🚇','🚈','🚉','🚊','🚝','🚞','🚋','🚌','🚍','🚎','🚐','🚑','🚒','🚓','🚔','🚕','🚖','🚗','🚘','🚙','🚚','🚛','🚜','🏎️','🏍️','🛵','🛺','🚲','🛴','🛹','🛼','🚏','🛣️','🛤️','🛢️','⛽','🚨','🚥','🚦','🛑','🚧','⚓','⛵','🛶','🚤','🛳️','⛴️','🛥️','🚢','✈️','🛩️','🛫','🛬','🪂','💺','🚁','🚟','🚠','🚡','🛰️','🚀','🛸','🛎️','🧳','⌛','⏳','⌚','⏰','🌙','☀️','⭐','🌟','🌠','🌌','☁️','⛅','⛈️','🌈','⚡','❄️','🔥','💧','🌊'],
        activities: ['🎃','🎄','🎆','🎇','🧨','✨','🎈','🎉','🎊','🎋','🎍','🎎','🎏','🎐','🎑','🧧','🎀','🎁','🎗️','🎟️','🎫','🎖️','🏆','🏅','🥇','🥈','🥉','⚽','⚾','🥎','🏀','🏐','🏈','🏉','🎾','🥏','🎳','🏏','🏑','🏒','🥍','🏓','🏸','🥊','🥋','🥅','⛳','⛸️','🎣','🤿','🎽','🎿','🛷','🥌','🎯','🪀','🪁','🔫','🎱','🔮','🪄','🎮','🕹️','🎰','🎲','🧩','🧸','🪅','🪆','♠️','♥️','♦️','♣️','🃏','🀄','🎴','🎭','🖼️','🎨','🧵','🪡','🧶','🪢'],
        objects: ['👓','🕶️','🥽','🥼','🦺','👔','👕','👖','🧣','🧤','🧥','🧦','👗','👘','🥻','🩱','🩲','🩳','👙','👚','👛','👜','👝','🛍️','🎒','👞','👟','🥾','🥿','👠','👡','👢','👑','👒','🎩','🎓','🧢','⛑️','📿','💄','💍','💎','🔇','🔈','🔉','🔊','📢','📣','📯','🔔','🔕','🎼','🎵','🎶','🎙️','🎤','🎧','📻','🎷','🎸','🎹','🎺','🎻','🪕','🥁','📱','📲','☎️','📞','📟','📠','🔋','🔌','💻','🖥️','🖨️','⌨️','🖱️','🖲️','💽','💾','💿','📀','🧮','🎥','🎬','📺','📷','📸','📹','📼','🔍','🔎','🕯️','💡','🔦','🏮','🪔','📔','📕','📖','📗','📘','📙','📚','📓','📒','📃','📜','📄','📰','📑','🔖','🏷️','💰','🪙','💴','💵','💶','💷','💸','💳','🧾','✉️','📧','📨','📩','📤','📥','📦','📫','📪','📬','📭','📮','🗳️','✏️','✒️','🖋️','🖊️','🖌️','🖍️','📝','💼','📁','📂','🗂️','📅','📆','🗒️','🗓️','📇','📈','📉','📊','📋','📌','📍','📎','🖇️','📏','📐','✂️','🗃️','🗄️','🗑️','🔒','🔓','🔏','🔐','🔑','🗝️','🔨','🪓','⛏️','⚒️','🛠️','🗡️','⚔️','💣','🏹','🛡️','🔧','🪛','🔩','⚙️','🗜️','⚖️','🔗','⛓️','🪝','🧰','🧲','🪜','⚗️','🧪','🧫','🧬','🔬','🔭','📡','💉','💊','🩹','🚪','🛗','🪞','🪟','🛏️','🛋️','🪑','🚽','🚿','🛁','🪒','🧴','🧷','🧹','🧺','🧻','🧼','🧽','🧯','🛒','🚬','⚰️','🪦','⚱️','🗿','🪧','🪪'],
        symbols: ['🏧','🚮','🚰','♿','🚹','🚺','🚻','🚼','🚾','🛂','🛃','🛄','🛅','⚠️','🚸','⛔','🚫','🚳','🚭','🚯','🚱','🚷','📵','🔞','☢️','☣️','⬆️','↗️','➡️','↘️','⬇️','↙️','⬅️','↖️','↕️','↔️','↩️','↪️','🔃','🔄','🔙','🔚','🔛','🔜','🔝','🛐','⚛️','🕉️','✡️','☸️','☯️','✝️','☦️','☪️','☮️','🕎','🔯','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','⛎','▶️','⏩','⏭️','⏯️','◀️','⏪','⏮️','🔼','⏫','🔽','⏬','⏸️','⏹️','⏺️','♀️','♂️','✖️','➕','➖','➗','♾️','‼️','⁉️','❓','❔','❕','❗','〰️','💱','💲','⚕️','♻️','⚜️','🔱','📛','🔰','⭕','✅','☑️','✔️','❌','❎','➰','➿','✳️','✴️','❇️','©️','®️','™️','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','🔠','🔡','🔢','🔣','🔤','🅰️','🆎','🅱️','🆑','🆒','🆓','ℹ️','🆔','Ⓜ️','🆕','🆖','🅾️','🆗','🅿️','🆘','🆙','🆚','🈁','🈶','🈯','🉐','🈹','🈚','🈲','🉑','🈸','🈴','🈳','㊗️','㊙️','🈺','🈵','🔴','🟠','🟡','🟢','🔵','🟣','🟤','⚫','⚪','🟥','🟧','🟨','🟩','🟦','🟪','🟫','⬛','⬜','◼️','◻️','◾','◽','▪️','▫️','🔶','🔷','🔸','🔹','🔺','🔻','💠','🔘','🔳','🔲','⭐'],
        flags: ['🏁','🚩','🎌','🏴','🏳️','🏳️‍🌈','🏳️‍⚧️','🏴‍☠️','🇦🇩','🇦🇪','🇦🇫','🇦🇬','🇦🇱','🇦🇲','🇦🇴','🇦🇷','🇦🇹','🇦🇺','🇦🇼','🇦🇿','🇧🇦','🇧🇧','🇧🇩','🇧🇪','🇧🇫','🇧🇬','🇧🇭','🇧🇮','🇧🇯','🇧🇷','🇧🇸','🇧🇹','🇧🇼','🇧🇾','🇧🇿','🇨🇦','🇨🇩','🇨🇫','🇨🇬','🇨🇭','🇨🇮','🇨🇱','🇨🇲','🇨🇳','🇨🇴','🇨🇷','🇨🇺','🇨🇻','🇨🇾','🇨🇿','🇩🇪','🇩🇰','🇩🇲','🇩🇴','🇩🇿','🇪🇨','🇪🇪','🇪🇬','🇪🇸','🇪🇹','🇪🇺','🇫🇮','🇫🇯','🇫🇷','🇬🇦','🇬🇧','🇬🇩','🇬🇪','🇬🇭','🇬🇮','🇬🇱','🇬🇲','🇬🇷','🇬🇹','🇬🇺','🇬🇾','🇭🇰','🇭🇳','🇭🇷','🇭🇺','🇮🇩','🇮🇪','🇮🇱','🇮🇳','🇮🇶','🇮🇷','🇮🇸','🇮🇹','🇯🇲','🇯🇵','🇰🇪','🇰🇬','🇰🇭','🇰🇷','🇰🇼','🇰🇿','🇱🇦','🇱🇧','🇱🇰','🇱🇷','🇱🇹','🇱🇺','🇱🇻','🇲🇦','🇲🇨','🇲🇩','🇲🇪','🇲🇬','🇲🇰','🇲🇱','🇲🇲','🇲🇳','🇲🇽','🇲🇾','🇲🇿','🇳🇦','🇳🇪','🇳🇱','🇳🇴','🇳🇵','🇳🇿','🇴🇲','🇵🇦','🇵🇪','🇵🇭','🇵🇰','🇵🇱','🇵🇲','🇵🇷','🇵🇸','🇵🇹','🇵🇾','🇶🇦','🇷🇴','🇷🇸','🇷🇺','🇷🇼','🇸🇦','🇸🇪','🇸🇬','🇸🇮','🇸🇰','🇸🇳','🇸🇷','🇸🇻','🇸🇾','🇹🇭','🇹🇯','🇹🇳','🇹🇷','🇹🇹','🇹🇼','🇺🇦','🇺🇬','🇺🇸','🇺🇾','🇺🇿','🇻🇦','🇻🇪','🇻🇳','🇾🇪','🇿🇦','🇿🇲','🇿🇼']
    };
    const field = document.querySelector('.emoji-picker-field');
    if (!field) return;
    const input = field.querySelector('input[name="emoji"]');
    const pickBtn = field.querySelector('.emoji-pick-btn');
    const clearBtn = field.querySelector('.emoji-clear-btn');
    const picker = field.querySelector('.emoji-picker');
    const tabs = picker.querySelector('.emoji-picker-tabs');
    const body = picker.querySelector('.emoji-picker-body');
    let current = order[0];

    order.forEach(function (key) {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.textContent = sections[key][0];
        tab.dataset.section = key;
        tab.title = sections[key][0];
        tab.addEventListener('click', function () {
            current = key;
            select(key);
        });
        tabs.appendChild(tab);

        const grid = document.createElement('div');
        grid.className = 'emoji-grid';
        grid.dataset.section = key;
        grid.hidden = true;
        sections[key].forEach(function (emoji) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = emoji;
            btn.addEventListener('click', function () {
                input.value = emoji;
                pickBtn.textContent = emoji;
                clearBtn.hidden = false;
                picker.hidden = true;
            });
            grid.appendChild(btn);
        });
        body.appendChild(grid);
    });

    function select(key) {
        body.querySelectorAll('.emoji-grid').forEach(function (grid) {
            grid.hidden = grid.dataset.section !== key;
        });
        tabs.querySelectorAll('button').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.dataset.section === key);
        });
    }

    pickBtn.addEventListener('click', function () {
        if (picker.hidden) {
            select(current);
        }
        picker.hidden = !picker.hidden;
    });

    clearBtn.addEventListener('click', function () {
        input.value = '';
        pickBtn.textContent = '😀';
        clearBtn.hidden = true;
    });

    document.addEventListener('click', function (event) {
        if (!field.contains(event.target)) {
            picker.hidden = true;
        }
    });

    if (input.value !== '') {
        clearBtn.hidden = false;
    }
})();
</script>
