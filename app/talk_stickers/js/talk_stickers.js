/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The Smart Picker's "Stickers", the widget that shows a sent sticker, and the
 * app's own page for phones (Talk's apps have no Smart Picker): there a sticker is
 * shared to Talk as an image through the phone's share sheet, or its link copied.
 *
 * Plain DOM, no build step: both register through the globals that
 * @nextcloud/vue's registerCustomPickerElement() and registerWidget() fill
 * (window._vue_richtext_custom_picker_elements and window._vue_richtext_widgets),
 * so they work with whichever copy of the library a page (Talk, Text, …) bundles,
 * loaded before or after this script. A picked sticker is sent as its link (the
 * "submit" event of the element); the widget renders such a link as the image.
 */
(function () {
	'use strict'

	const APP = 'talk_stickers'
	const RICH_OBJECT_TYPE = 'talk_stickers_sticker'
	const PAGE = 120
	const RECENT_KEY = 'talk_stickers.recent'
	const RECENT_MAX = 40

	const t = (text, vars) => (typeof window.t === 'function' ? window.t(APP, text, vars) : text)

	function url(path) {
		if (window.OC && typeof window.OC.generateUrl === 'function') {
			return window.OC.generateUrl(path)
		}
		return (window.OC && window.OC.webroot ? window.OC.webroot : '') + '/index.php' + path
	}

	function requestToken() {
		return (window.OC && window.OC.requestToken) || document.head.dataset.requesttoken || ''
	}

	let catalogue = null
	function loadCatalogue() {
		if (catalogue === null) {
			catalogue = fetch(url('/apps/talk_stickers/api/packs'), {
				headers: { requesttoken: requestToken(), Accept: 'application/json' },
				credentials: 'same-origin',
			}).then((response) => {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status)
				}
				return response.json()
			}).catch((error) => {
				catalogue = null
				throw error
			})
		}
		return catalogue
	}

	function readRecent() {
		try {
			const value = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]')
			return Array.isArray(value) ? value : []
		} catch (e) {
			return []
		}
	}

	function remember(sticker) {
		try {
			const recent = readRecent().filter((s) => s.link !== sticker.link)
			recent.unshift(sticker)
			window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, RECENT_MAX)))
		} catch (e) {
			// Without storage there is simply no "Recent" tab.
		}
	}

	function el(tag, attributes, children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(attributes || {})) {
			if (key === 'text') {
				node.textContent = value
			} else if (key.startsWith('on')) {
				node.addEventListener(key.slice(2), value)
			} else if (value !== undefined && value !== null && value !== false) {
				node.setAttribute(key, value === true ? '' : value)
			}
		}
		for (const child of children || []) {
			if (child) {
				node.appendChild(child)
			}
		}
		return node
	}

	/** The packs as tabs: recent, the shared folder's, then the shipped ones. */
	function tabsOf(data) {
		const link = (pack, id) => data.linkBase.replace('/xpackx/', '/' + pack + '/').replace(/XIDX$/, id)
		const tabs = []
		const recent = readRecent()
		if (recent.length > 0) {
			tabs.push({ id: 'recent', title: t('Recent'), categories: [], stickers: recent })
		}
		for (const pack of data.family || []) {
			tabs.push({
				id: pack.id,
				title: pack.title,
				family: true,
				categories: [],
				stickers: pack.stickers.map((s) => ({ name: s.n, image: s.u, link: link('family', s.i), keywords: '' })),
			})
		}
		for (const pack of data.packs || []) {
			tabs.push({
				id: pack.id,
				title: pack.title,
				attribution: pack.attribution,
				licenseUrl: pack.licenseUrl,
				categories: pack.categories,
				stickers: pack.stickers.map((s) => ({
					name: s.n,
					image: data.imageBase + pack.id + '/' + s.f,
					link: link(pack.id, s.i),
					category: s.c,
					keywords: s.k,
				})),
			})
		}
		if (!(data.family || []).length) {
			// Shown so that everyone finds out how to add their own.
			tabs.splice(recent.length > 0 ? 1 : 0, 0, { id: 'own', title: t('Ours'), family: true, categories: [], stickers: [] })
		}
		return tabs
	}

	/** The picker in element; onPick(sticker) gets {name, image, link}. */
	function renderPicker(element, onPick) {
		const root = el('div', { class: 'talk-stickers' })
		element.appendChild(root)
		const state = { tab: null, category: null, query: '', shown: PAGE, tabs: [], folder: 'Stickers' }

		const search = el('input', {
			type: 'search',
			class: 'talk-stickers__search',
			placeholder: t('Search stickers'),
			'aria-label': t('Search stickers'),
			oninput: () => {
				state.query = search.value.trim().toLowerCase()
				state.shown = PAGE
				draw()
			},
		})
		const tabBar = el('div', { class: 'talk-stickers__tabs', role: 'tablist' })
		const chips = el('div', { class: 'talk-stickers__categories' })
		const grid = el('div', { class: 'talk-stickers__grid' })
		const footer = el('div', { class: 'talk-stickers__footer' })
		root.append(search, tabBar, chips, grid, footer)
		grid.appendChild(el('p', { class: 'talk-stickers__note', text: t('Loading stickers …') }))

		function pick(sticker) {
			remember({ name: sticker.name, image: sticker.image, link: sticker.link })
			onPick(sticker)
		}

		function visible(tab) {
			let stickers = tab.stickers
			if (state.query) {
				stickers = stickers.filter((s) => s.name.toLowerCase().includes(state.query)
					|| (s.keywords || '').includes(state.query))
			} else if (state.category !== null) {
				stickers = stickers.filter((s) => s.category === state.category)
			}
			return stickers
		}

		function draw() {
			const tab = state.tabs.find((x) => x.id === state.tab) || state.tabs[0]
			tabBar.replaceChildren(...state.tabs.map((x) => el('button', {
				type: 'button',
				role: 'tab',
				class: 'talk-stickers__tab' + (x === tab ? ' talk-stickers__tab--active' : ''),
				'aria-selected': x === tab ? 'true' : 'false',
				text: x.title,
				onclick: () => {
					state.tab = x.id
					state.category = null
					state.shown = PAGE
					draw()
				},
			})))
			chips.replaceChildren(...(state.query ? [] : tab.categories).map((name, index) => el('button', {
				type: 'button',
				class: 'talk-stickers__category' + (state.category === index ? ' talk-stickers__category--active' : ''),
				text: name,
				onclick: () => {
					state.category = state.category === index ? null : index
					state.shown = PAGE
					draw()
				},
			})))
			const stickers = visible(tab)
			const cells = stickers.slice(0, state.shown).map((s) => el('button', {
				type: 'button',
				class: 'talk-stickers__sticker',
				title: s.name,
				'aria-label': s.name,
				onclick: () => pick(s),
			}, [el('img', { src: s.image, alt: '', loading: 'lazy', decoding: 'async' })]))
			if (stickers.length > state.shown) {
				cells.push(el('button', {
					type: 'button',
					class: 'talk-stickers__more',
					text: t('Show more'),
					onclick: () => {
						state.shown += PAGE
						draw()
					},
				}))
			}
			if (cells.length === 0) {
				cells.push(tab.family
					? el('p', { class: 'talk-stickers__note' }, [
						document.createTextNode(t('Add your own stickers: upload images to the folder “{folder}” in Files, one subfolder per pack.', { folder: state.folder }) + ' '),
						el('a', { href: url('/apps/files/?dir=/' + encodeURIComponent(state.folder)), target: '_blank', rel: 'noopener', text: t('Open the folder') }),
					])
					: el('p', { class: 'talk-stickers__note', text: t('No stickers found') }))
			}
			grid.replaceChildren(...cells)
			// The chosen pack in view, also when the row scrolls sideways on a phone.
			tabBar.querySelector('.talk-stickers__tab--active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
			footer.replaceChildren(...(tab.attribution
				? [el('a', { href: tab.licenseUrl, target: '_blank', rel: 'noopener noreferrer', text: tab.attribution })]
				: []))
		}

		loadCatalogue().then((data) => {
			state.folder = data.folder || state.folder
			state.tabs = tabsOf(data)
			state.tab = state.tabs.length ? state.tabs[0].id : null
			draw()
			// Not on phones, where the keyboard would cover the stickers.
			if (!window.matchMedia('(pointer: coarse)').matches) {
				search.focus()
			}
		}).catch(() => {
			grid.replaceChildren(el('p', { class: 'talk-stickers__note', text: t('The stickers could not be loaded.') }))
		})
		return { element, object: null }
	}

	/**
	 * The sticker as a file to share: as served, except SVG (Twemoji), which
	 * messengers would send as a document, drawn into a PNG.
	 */
	async function stickerFile(sticker) {
		const response = await fetch(sticker.image, { credentials: 'same-origin' })
		if (!response.ok) {
			throw new Error('HTTP ' + response.status)
		}
		let blob = await response.blob()
		const name = sticker.name.replace(/[^\p{L}\p{N} _-]+/gu, '').trim() || 'sticker'
		if (blob.type === 'image/svg+xml') {
			const image = new Image()
			const source = URL.createObjectURL(blob)
			try {
				image.src = source
				await image.decode()
				const canvas = document.createElement('canvas')
				canvas.width = canvas.height = 512
				canvas.getContext('2d').drawImage(image, 0, 0, 512, 512)
				blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
			} finally {
				URL.revokeObjectURL(source)
			}
		}
		const extension = { 'image/png': 'png', 'image/webp': 'webp', 'image/gif': 'gif', 'image/jpeg': 'jpg' }[blob.type] || 'png'
		return new File([blob], name + '.' + extension, { type: blob.type })
	}

	async function copyLink(link) {
		try {
			await navigator.clipboard.writeText(link)
			return true
		} catch (e) {
			return false
		}
	}

	/**
	 * The chosen sticker on the page: Share (the image, through the share sheet)
	 * and Copy link. Sharing must start from a tap of its own (iOS allows it only
	 * directly from one), so the file is prepared while the sheet is shown.
	 */
	function showSheet(page, sticker) {
		const status = el('p', { class: 'talk-stickers-sheet__status', role: 'status' })
		const share = el('button', { type: 'button', class: 'primary talk-stickers-sheet__share', disabled: true, text: t('Share') })
		const copy = el('button', { type: 'button', text: t('Copy link') })
		const close = () => sheet.remove()
		const sheet = el('div', { class: 'talk-stickers-sheet', onclick: (event) => event.target === sheet && close() }, [
			el('div', { class: 'talk-stickers-sheet__panel', role: 'dialog', 'aria-label': sticker.name }, [
				el('img', { class: 'talk-stickers-sheet__image', src: sticker.image, alt: sticker.name }),
				el('p', { class: 'talk-stickers-sheet__name', text: sticker.name }),
				el('div', { class: 'talk-stickers-sheet__actions' }, [share, copy,
					el('button', { type: 'button', text: t('Close'), onclick: close })]),
				status,
			]),
		])
		page.querySelector('.talk-stickers-sheet')?.remove()
		page.appendChild(sheet)

		let file = null
		const canShareFiles = typeof navigator.canShare === 'function'
		if (canShareFiles) {
			status.textContent = t('Preparing …')
			stickerFile(sticker).then((prepared) => {
				if (navigator.canShare({ files: [prepared] })) {
					file = prepared
					share.disabled = false
					status.textContent = ''
				} else {
					status.textContent = t('This browser cannot share images: copy the link and paste it into Talk.')
				}
			}).catch(() => {
				status.textContent = t('The sticker could not be loaded.')
			})
		} else {
			status.textContent = t('This browser cannot share images: copy the link and paste it into Talk.')
		}
		share.addEventListener('click', () => {
			if (file === null) {
				return
			}
			navigator.share({ files: [file], title: sticker.name }).then(close).catch((error) => {
				if (error && error.name !== 'AbortError') {
					status.textContent = t('Sharing failed: copy the link and paste it into Talk.')
				}
			})
		})
		copy.addEventListener('click', () => {
			copyLink(sticker.link).then((copied) => {
				status.textContent = copied ? t('Link copied: paste it into Talk.') : sticker.link
			})
		})
	}

	function renderPage(page) {
		const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone
		let tipSeen = false
		try {
			tipSeen = window.localStorage.getItem('talk_stickers.tip') === '1'
		} catch (e) {
			// Shown again next time.
		}
		if (!standalone && !tipSeen) {
			const tip = el('p', { class: 'talk-stickers-page__tip' }, [
				document.createTextNode(t('Tip: add this page to your home screen to have the stickers at hand. Tap a sticker, then “Share” and choose Talk.') + ' '),
				el('button', {
					type: 'button',
					text: t('OK'),
					onclick: () => {
						tip.remove()
						try {
							window.localStorage.setItem('talk_stickers.tip', '1')
						} catch (e) {
							// Shown again next time.
						}
					},
				}),
			])
			page.appendChild(tip)
		}
		const picker = el('div', { class: 'talk-stickers-page__picker' })
		page.appendChild(picker)
		renderPicker(picker, (sticker) => showSheet(page, sticker))
	}

	function renderWidget(element, { richObject }) {
		element.replaceChildren(el('a', {
			class: 'talk-stickers-widget',
			href: richObject.link,
			target: '_blank',
			rel: 'noopener noreferrer',
			title: richObject.name + ' · ' + richObject.pack,
		}, [el('img', { src: richObject.image, alt: richObject.name, loading: 'lazy' })]))
	}

	window._vue_richtext_custom_picker_elements = window._vue_richtext_custom_picker_elements || {}
	if (!window._vue_richtext_custom_picker_elements[APP]) {
		window._vue_richtext_custom_picker_elements[APP] = {
			id: APP,
			callback: (element) => renderPicker(element, (sticker) => {
				element.dispatchEvent(new CustomEvent('submit', { detail: sticker.link }))
			}),
			onDestroy: () => {},
			size: 'large',
		}
	}
	window._vue_richtext_widgets = window._vue_richtext_widgets || {}
	if (!window._vue_richtext_widgets[RICH_OBJECT_TYPE]) {
		window._vue_richtext_widgets[RICH_OBJECT_TYPE] = {
			id: RICH_OBJECT_TYPE,
			callback: renderWidget,
			onDestroy: () => {},
			hasInteractiveView: false,
			fullWidth: false,
			isResizable: false,
		}
	}

	function startPage() {
		const page = document.getElementById('talk-stickers-page')
		if (page) {
			renderPage(page)
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', startPage)
	} else {
		startPage()
	}
})()
