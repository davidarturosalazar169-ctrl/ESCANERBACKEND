import { chromium } from 'playwright'

const url = process.argv[2]
const customSelectors = JSON.parse(process.argv[3] || '{}')
const previewOnly = process.argv[4] === '--preview'
if (!url) {
  console.error(JSON.stringify({ error: 'URL requerida.' }))
  process.exit(2)
}

const host = new URL(url).hostname.toLowerCase()
const rules = [
  { match: 'amazon.', name: 'Amazon', selectors: { name: '#productTitle', price: '.a-price .a-offscreen, #priceblock_ourprice, #priceblock_dealprice, .priceToPay', image: '#landingImage, #imgBlkFront' } },
  { match: 'mercadolibre.', name: 'Mercado Libre', selectors: { name: '.ui-pdp-title, [data-testid="ui-pdp-title"]', price: '.andes-money-amount__fraction, [data-testid*="price"]', image: '.ui-pdp-gallery__figure img, img[src*="mlstatic.com"]' } },
  { match: 'ebay.', name: 'eBay', selectors: { name: '.x-item-title__mainTitle, h1', price: '.x-price-primary span, [itemprop="price"]', image: '.ux-image-carousel-item img, [itemprop="image"]' } },
  { match: 'liverpool.', name: 'Liverpool', selectors: { name: 'h1, [data-testid="product-name"]', price: '[data-testid="discounted"], [data-testid="price"], [itemprop="price"]', image: '[data-testid*="gallery__main__image__image"], img[src*="liverpool.com.mx/i/"]' } },
  { match: 'buscalibre.', name: 'Buscalibre', selectors: { name: 'h1, .titulo, .product-name', price: '.precio, .price, [itemprop="price"]', image: '.product-image img, [itemprop="image"]' } },
]
const rule = rules.find((item) => host.includes(item.match))
const selectors = { ...(rule?.selectors || {}), ...customSelectors }

const mercadoLibreItemId = (value) => {
  const parsedUrl = new URL(value)
  const itemFromQuery = parsedUrl.searchParams.get('pdp_filters')?.match(/item_id[:=](MLM\d{6,})/i)?.[1]
    || parsedUrl.searchParams.get('item_id')
    || parsedUrl.searchParams.get('wid')
  return (itemFromQuery || parsedUrl.pathname.match(/\bMLM\d{6,}\b/i)?.[0])?.toUpperCase()
}

const mercadoLibreApi = async () => {
  if (!host.includes('mercadolibre.')) return null
  const itemId = mercadoLibreItemId(url)
  if (!itemId) return null
  try {
    const headers = { Accept: 'application/json', 'User-Agent': 'EscanerParcial2/1.0 (+product research)' }
    const accessToken = process.env.MERCADOLIBRE_ACCESS_TOKEN?.trim()
    if (accessToken && accessToken !== 'TU_TOKEN_OFICIAL') {
      headers.Authorization = `Bearer ${accessToken}`
    }
    const response = await fetch(`https://api.mercadolibre.com/items/${itemId}`, {
      headers,
    })
    if (!response.ok) return null
    const item = await response.json()
    if (!item.title || item.price === undefined || item.price === null) return null
    return {
      name: item.title,
      price: item.price,
      currency: item.currency_id || 'MXN',
      image: item.thumbnail || item.pictures?.[0]?.secure_url || null,
      custom_fields: {},
    }
  } catch {
    return null
  }
}

const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
})
const page = await browser.newPage({
  viewport: { width: 1440, height: 1000 },
  userAgent: 'EscanerParcial2/1.0 (+product research)',
})

try {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 })
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {})
  await page.waitForTimeout(1500)
  if (previewOnly) {
    console.log(JSON.stringify({ status: 'completed', html: await page.content() }))
    process.exitCode = 0
  } else {
  const apiData = await mercadoLibreApi()
  const visibleContent = `${await page.title()} ${await page.locator('body').innerText({ timeout: 3000 }).catch(() => '')}`.toLowerCase()
  const challenge = /(captcha|robot check|verify you are human|unusual traffic|access denied|are you a robot)/i.test(visibleContent)
  if (apiData) {
    console.log(JSON.stringify({ status: 'completed', store: rule?.name, ...apiData }))
    process.exitCode = 0
  } else if (challenge) {
    console.log(JSON.stringify({ status: 'challenge', store: rule?.name ?? host, error: 'La tienda requiere CAPTCHA o verificación humana.' }))
    process.exitCode = 3
  } else {
    const data = await page.evaluate((selectors) => {
      const text = (selector) => {
        if (!selector) return null
        return [...document.querySelectorAll(selector)]
          .map((element) => element.textContent?.trim())
          .find(Boolean) || null
      }
      const attr = (selector, names) => {
        if (!selector) return null
        const element = document.querySelector(selector)
        for (const name of names) {
          const value = element?.getAttribute(name)
          if (value) return value
        }
        return null
      }
      const customFields = Object.fromEntries(
        Object.entries(selectors || {})
          .filter(([key, selector]) => !['name', 'price', 'image'].includes(key) && selector)
          .map(([key, selector]) => [key, text(selector)])
      )
      const jsonLd = [...document.querySelectorAll('script[type="application/ld+json"]')].flatMap((node) => {
        try {
          const value = JSON.parse(node.textContent)
          return value?.['@graph'] ?? (Array.isArray(value) ? value : [value])
        } catch { return [] }
      }).find((item) => {
        const type = item?.['@type']
        return type === 'Product' || type === 'ProductGroup' || type?.includes?.('Product')
      })
      const offer = Array.isArray(jsonLd?.offers) ? jsonLd.offers[0] : jsonLd?.offers
      const embeddedText = [...document.scripts].map((node) => node.textContent || '').find((text) => /price|product|title/i.test(text)) || ''
      const embeddedName = embeddedText.match(/"(?:title|name)"\s*:\s*"([^"]{3,200})"/i)?.[1]
      const embeddedPrice = embeddedText.match(/["'](?:price|amount|ecomm_totalvalue)["']\s*:\s*["']?([\d,.]+)/i)?.[1]
      const meta = (selector) => document.querySelector(selector)?.getAttribute('content') || null
      const firstText = (...values) => values.find((value) => typeof value === 'string' && value.trim())?.trim() || null
      const image = Array.isArray(jsonLd?.image) ? jsonLd.image[0] : jsonLd?.image
      const liverpoolPrice = (() => {
        const node = document.querySelector('[data-testid="discounted"]')
        if (!node) return null
        const text = node.innerText.replace(/\s+/g, ' ').trim()
        const match = text.match(/[$\s]*([\d,]+)(?:\s*[.]\s*(\d{2}))?/)
        return match ? `${match[1]}${match[2] ? `.${match[2]}` : ''}` : null
      })()
      return {
        name: firstText(jsonLd?.name, text(selectors?.name), text('h1'), meta('meta[property="og:title"]'), embeddedName, document.title),
        price: firstText(offer?.price, liverpoolPrice, text(selectors?.price), text('.priceToPay'), meta('meta[property="product:price:amount"]'), embeddedPrice),
        currency: firstText(offer?.priceCurrency, meta('meta[property="product:price:currency"]'), 'MXN'),
        image: firstText(image, attr(selectors?.image, ['src', 'data-src', 'data-old-hires']), meta('meta[property="og:image"]')),
        custom_fields: customFields,
        diagnostics: {
          title: document.title,
          url: location.href,
          jsonLdProductFound: Boolean(jsonLd),
          selectorMatches: Object.fromEntries(
            Object.entries(selectors || {}).map(([key, selector]) => [key, selector ? document.querySelectorAll(selector).length : 0])
          ),
          metaTitle: meta('meta[property="og:title"]'),
          metaPrice: meta('meta[property="product:price:amount"]'),
          bodyPreview: document.body?.innerText?.trim().slice(0, 300) || '',
        },
      }
    }, selectors)
    const genericName = !data.name || [
      'amazon',
      'amazon.com.mx',
      'mercado libre',
      'ebay',
      'seguir al autor',
    ].includes(data.name.trim().toLowerCase())
    if (genericName || (!data.price && !data.image)) {
      const message = host.includes('mercadolibre.')
        ? 'Mercado Libre bloqueó la página o API. Configura un token OAuth válido en MERCADOLIBRE_ACCESS_TOKEN.'
        : 'La tienda no entregó datos de producto. Puede requerir JavaScript adicional, CAPTCHA o sesión.'
      console.log(JSON.stringify({ status: 'challenge', store: rule?.name ?? host, error: message, diagnostics: data.diagnostics }))
      process.exitCode = 3
    } else {
      console.log(JSON.stringify({ status: 'completed', store: rule?.name, ...data }))
    }
  }
  }
} catch (error) {
  console.log(JSON.stringify({ status: 'failed', error: error.message, url }))
  process.exitCode = 1
} finally {
  await browser.close()
}
