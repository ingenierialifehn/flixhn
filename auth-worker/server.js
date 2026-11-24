import express from 'express';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

chromium.use(StealthPlugin());

const app = express();
app.use(express.json({ limit: '1mb' }));

const PORT = Number(process.env.PORT || 4000);
const COOKIES_DIR = '/app/cookies';

const PLATFORM_CONFIGS = {
  netflix: {
    canonical: 'netflix',
    name: 'Netflix',
    loginUrl: 'https://www.netflix.com/login',
    twoStep: false,
    emailSelectors: [
      'input[name="userLoginId"]',
      'input[data-uia="field-userLoginId"]',
      '#id_userLoginId',
      'input[type="email"]',
    ],
    continueSelectors: [
      'button[data-uia="login-submit-button"]',
      'button[data-uia="continue-button"]',
      'button[type="submit"]',
    ],
    passwordSelectors: [
      'input[name="password"]',
      'input[data-uia="field-password"]',
      '#id_password',
      'input[type="password"]',
    ],
    submitSelectors: [
      'button[data-uia="login-submit-button"]',
      'button[type="submit"]',
    ],
    successUrlPatterns: [/\/browse/i, /\/profiles/i, /\/YourAccount/i],
    loginUrlPatterns: [/\/login/i, /\/signin/i],
    authCookieNames: ['NetflixId', 'SecureNetflixId'],
  },
  disney: {
    canonical: 'disney',
    name: 'Disney+',
    loginUrl: 'https://www.disneyplus.com/login',
    twoStep: true,
    emailSelectors: [
      'input[id="email"]',
      'input[name="email"]',
      'input[data-testid="email-input"]',
      'input[type="email"]',
    ],
    continueSelectors: [
      'button[data-testid="continue-btn"]',
      'button[name="dssLoginSubmit"]',
      'button[type="submit"]',
    ],
    passwordSelectors: [
      'input[id="password"]',
      'input[name="password"]',
      'input[data-testid="password-input"]',
      'input[type="password"]',
    ],
    submitSelectors: [
      'button[data-testid="password-continue-login"]',
      'button[data-testid="login-btn"]',
      'button[name="dssLoginSubmit"]',
      'button[type="submit"]',
    ],
    successUrlPatterns: [/\/home/i, /\/select-profile/i, /\/browse/i],
    loginUrlPatterns: [/\/login/i, /\/identity\/login/i],
    authCookieNames: [],
  },
  amazon_prime: {
    canonical: 'amazon_prime',
    name: 'Prime Video',
    loginUrl:
      'https://www.amazon.com/ap/signin?openid.pape.max_auth_age=0&openid.return_to=https%3A%2F%2Fwww.primevideo.com%2Fauth%2Freturn%2Fref%3Dav_auth_ap&openid.identity=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0%2Fidentifier_select&openid.assoc_handle=amzn_prime_video_sso_us&openid.mode=checkid_setup&openid.ns=http%3A%2F%2Fspecs.openid.net%2Fauth%2F2.0',
    twoStep: true,
    emailSelectors: [
      '#ap_email',
      '#ap_email_login',
      'input[name="email"]',
      'input[type="email"]',
    ],
    continueSelectors: [
      '#continue',
      'input#continue',
      'button[type="submit"]',
      'input[type="submit"]',
    ],
    passwordSelectors: [
      '#ap_password',
      'input[name="password"]',
      'input[type="password"]',
    ],
    submitSelectors: [
      '#signInSubmit',
      'input#signInSubmit',
      'button[type="submit"]',
      'input[type="submit"]',
    ],
    successUrlPatterns: [
      /primevideo\.com\/(?!.*ap\/signin)/i,
      /\/storefront/i,
      /\/region\//i,
      /\/profiles/i,
    ],
    loginUrlPatterns: [/\/ap\/signin/i, /\/signin/i],
    authCookieNames: ['at-main', 'sess-at-main', 'x-main'],
  },
  hbo_max: {
    canonical: 'hbo_max',
    name: 'Max',
    loginUrl: 'https://auth.max.com/login',
    twoStep: true,
    emailSelectors: [
      'input[name="email"]',
      '#login-username-input',
      'input[autocomplete="username"]',
      'input[type="email"]',
    ],
    continueSelectors: [
      'button[data-testid="gis.login.submit"]',
      'button[type="submit"]',
    ],
    passwordSelectors: [
      'input[name="password"]',
      '#login-password-input',
      'input[autocomplete="current-password"]',
      'input[type="password"]',
    ],
    submitSelectors: [
      'button[data-testid="gis.login.submit"]',
      'button[type="submit"]',
    ],
    successUrlPatterns: [/play\.max\.com/i, /\/profile-picker/i, /\/home/i],
    loginUrlPatterns: [/auth\.max\.com\/login/i, /\/login/i],
    authCookieNames: [],
  },
  apple_tv: {
    canonical: 'apple_tv',
    name: 'Apple TV+',
    loginUrl: 'https://tv.apple.com/',
    twoStep: true,
    preClickSelectors: [
      'button[data-testid="sign-in-button"]',
      'button:has-text("Sign In")',
      'button:has-text("Iniciar sesión")',
      'a:has-text("Sign In")',
    ],
    emailSelectors: [
      '#account_name_text_field',
      'input[name="accountName"]',
      'input[type="email"]',
    ],
    continueSelectors: ['#sign-in', 'button[id="sign-in"]', 'button[type="submit"]'],
    passwordSelectors: [
      '#password_text_field',
      'input[name="password"]',
      'input[type="password"]',
    ],
    submitSelectors: ['#sign-in', 'button[id="sign-in"]', 'button[type="submit"]'],
    successUrlPatterns: [/tv\.apple\.com\/(?!.*signin)/i],
    loginUrlPatterns: [/idmsa\.apple\.com/i, /\/signin/i],
    authCookieNames: ['media-user-token'],
  },
  paramount: {
    canonical: 'paramount',
    name: 'Paramount+',
    loginUrl: 'https://www.paramountplus.com/account/signin/',
    twoStep: true,
    emailSelectors: ['input[name="email"]', '#email', 'input[type="email"]'],
    continueSelectors: [
      'button[type="submit"]',
      'button:has-text("Continue")',
      'button:has-text("Continuar")',
    ],
    passwordSelectors: ['input[name="password"]', '#password', 'input[type="password"]'],
    submitSelectors: [
      'button[type="submit"]',
      'button:has-text("Sign In")',
      'button:has-text("Iniciar sesión")',
    ],
    successUrlPatterns: [
      /\/home/i,
      /\/user-profile/i,
      /\/shows/i,
      /\/movies/i,
      /\/account\/(?!signin)/i,
    ],
    loginUrlPatterns: [/\/account\/signin/i, /\/signin/i],
    authCookieNames: [],
  },
};

const PLATFORM_ALIASES = {
  netflix: 'netflix',
  disney: 'disney',
  disney_plus: 'disney',
  disneyplus: 'disney',
  'disney+': 'disney',
  prime: 'amazon_prime',
  prime_video: 'amazon_prime',
  primevideo: 'amazon_prime',
  amazon: 'amazon_prime',
  amazon_prime: 'amazon_prime',
  max: 'hbo_max',
  hbo: 'hbo_max',
  hbo_max: 'hbo_max',
  hbomax: 'hbo_max',
  apple: 'apple_tv',
  apple_tv: 'apple_tv',
  appletv: 'apple_tv',
  apple_tv_plus: 'apple_tv',
  'apple_tv+': 'apple_tv',
  paramount: 'paramount',
  paramount_plus: 'paramount',
  paramountplus: 'paramount',
  'paramount+': 'paramount',
};

function resolvePlatform(rawPlatform) {
  const normalized = String(rawPlatform || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '_');

  const configKey = PLATFORM_ALIASES[normalized];
  if (!configKey || !PLATFORM_CONFIGS[configKey]) {
    return null;
  }

  const safeFilenameBase = normalized.replace(/[^a-z0-9_-]/g, '_') || configKey;
  return {
    config: PLATFORM_CONFIGS[configKey],
    fileBase: safeFilenameBase,
  };
}

async function findVisibleLocator(page, selectors, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const frames = [page.mainFrame(), ...page.frames().filter((f) => f !== page.mainFrame())];

    for (const frame of frames) {
      for (const selector of selectors) {
        try {
          const locator = frame.locator(selector).first();
          if (await locator.isVisible({ timeout: 200 })) {
            return { frame, locator, selector };
          }
        } catch {
          // Ignore detached frames or non-matching selectors while polling
        }
      }
    }

    await page.waitForTimeout(250);
  }

  return null;
}

async function dismissCookieBanners(page) {
  const bannerSelectors = [
    '#onetrust-accept-btn-handler',
    'button[id*="accept"]',
    'button:has-text("Accept All")',
    'button:has-text("Aceptar todas")',
    'button:has-text("Aceptar")',
  ];

  for (const selector of bannerSelectors) {
    try {
      const btn = page.locator(selector).first();
      if (await btn.isVisible({ timeout: 500 })) {
        await btn.click({ timeout: 1500 });
        await page.waitForTimeout(300);
        break;
      }
    } catch {
      // Optional banner; continue normally
    }
  }
}

async function getVisiblePageText(page) {
  const texts = [];
  const frames = [page.mainFrame(), ...page.frames().filter((f) => f !== page.mainFrame())];

  for (const frame of frames) {
    try {
      const text = await frame.evaluate(() => document.body?.innerText || '');
      if (text) {
        texts.push(text);
      }
    } catch {
      // Ignore cross-origin or detached frames
    }
  }

  return texts.join('\n').replace(/\s+/g, ' ').trim();
}

async function detectChallengeOrError(page) {
  const url = page.url();

  // 1. CAPTCHA / anti-bot challenge detection
  const captchaSelectors = [
    'iframe[src*="recaptcha"]',
    'iframe[src*="hcaptcha"]',
    'iframe[src*="arkoselabs"]',
    'iframe[src*="funcaptcha"]',
    'iframe[src*="turnstile"]',
    'iframe[title*="captcha" i]',
    '#auth-captcha-image',
    'input[name="cvf_captcha_input"]',
    '.g-recaptcha',
    '.h-captcha',
    '[data-testid*="captcha" i]',
  ];

  const frames = [page.mainFrame(), ...page.frames().filter((f) => f !== page.mainFrame())];
  for (const frame of frames) {
    for (const selector of captchaSelectors) {
      try {
        if (await frame.locator(selector).first().isVisible({ timeout: 150 })) {
          return {
            error: 'CAPTCHA_REQUIRED',
            details:
              'La plataforma solicitó verificación CAPTCHA / anti-bot. No se puede completar automáticamente en modo headless.',
          };
        }
      } catch {
        // Ignore
      }
    }
  }

  const pageText = await getVisiblePageText(page);
  const lowerText = pageText.toLowerCase();

  if (
    /captcha|verify you are human|verifica que eres humano|solve this puzzle|resuelve este rompecabezas|enter the characters you see|introduce los caracteres que ves|cloudflare|robot/i.test(
      lowerText
    ) ||
    /\/cvf\/|captcha/i.test(url)
  ) {
    return {
      error: 'CAPTCHA_REQUIRED',
      details:
        'La plataforma presentó un reto CAPTCHA o verificación humana que requiere intervención manual.',
    };
  }

  // 2. Two-Factor Authentication (2FA / OTP / Passcode) detection
  const twoFactorSelectors = [
    'input[name="otpCode"]',
    'input[id*="auth-mfa"]',
    'input[autocomplete="one-time-code"]',
    'input[data-testid*="otp" i]',
    'input[data-testid*="passcode" i]',
    'input[name*="verification" i]',
  ];

  for (const frame of frames) {
    for (const selector of twoFactorSelectors) {
      try {
        if (await frame.locator(selector).first().isVisible({ timeout: 150 })) {
          return {
            error: 'TWO_FACTOR_REQUIRED',
            details:
              'La cuenta requiere verificación en dos pasos (2FA / código OTP) para iniciar sesión.',
          };
        }
      } catch {
        // Ignore
      }
    }
  }

  if (
    /\/ap\/mfa|\/two-factor|\/2fa|\/verify|\/passcode|\/check-email/i.test(url) ||
    /two-factor|two-step verification|verificación en dos pasos|código de verificación|verification code|one-time passcode|código de un solo uso|enter the code|ingresa el código|approve the notification|aprueba la notificación|check your email for a code|revisa tu correo/i.test(
      lowerText
    )
  ) {
    return {
      error: 'TWO_FACTOR_REQUIRED',
      details:
        'La plataforma solicitó autenticación de dos factores (2FA) o un código de verificación por correo/SMS.',
    };
  }

  // 3. Invalid credentials detection
  const errorBannerSelectors = [
    '[data-uia="error-message-container"]',
    '.ui-message-contents',
    '#auth-error-message-box',
    '[role="alert"]',
    '[data-testid*="error" i]',
    '.error-message',
  ];

  let bannerText = '';
  for (const frame of frames) {
    for (const selector of errorBannerSelectors) {
      try {
        const loc = frame.locator(selector).first();
        if (await loc.isVisible({ timeout: 150 })) {
          const txt = (await loc.innerText()).trim();
          if (txt) {
            bannerText = txt;
            break;
          }
        }
      } catch {
        // Ignore
      }
    }
    if (bannerText) break;
  }

  const combinedErrorCheck = `${bannerText} ${lowerText}`;
  if (
    /incorrect password|contraseña incorrecta|wrong password|invalid email|invalid credentials|credenciales inválidas|credenciales incorrectas|sorry, we can't find an account|no encontramos una cuenta|cannot find an account|we cannot find an account|your password is incorrect|incorrect email or password|correo electrónico o contraseña incorrectos|revisa tu contraseña|sign-in failed|authentication failed/i.test(
      combinedErrorCheck
    )
  ) {
    return {
      error: 'INVALID_CREDENTIALS',
      details: bannerText || 'Correo electrónico o contraseña incorrectos según la plataforma.',
    };
  }

  if (bannerText && bannerText.length > 3 && bannerText.length < 300) {
    return {
      error: 'LOGIN_REJECTED',
      details: bannerText,
    };
  }

  return null;
}

function isLoginSuccessful(url, cookies, config) {
  const matchesSuccessUrl = config.successUrlPatterns.some((re) => re.test(url));
  const stillOnLoginUrl = config.loginUrlPatterns.some((re) => re.test(url));

  const cookieNames = new Set(cookies.map((c) => c.name));
  const hasKnownAuthCookie =
    config.authCookieNames.length > 0 &&
    config.authCookieNames.some((name) => cookieNames.has(name));

  if (matchesSuccessUrl && !stillOnLoginUrl) {
    return true;
  }

  if (hasKnownAuthCookie && !stillOnLoginUrl) {
    return true;
  }

  return false;
}

app.get('/health', (_req, res) => {
  res.json({ ok: true, service: 'auth-worker' });
});

app.post('/login', async (req, res) => {
  const { platform, email, password } = req.body || {};

  if (!platform || !email || !password) {
    return res.status(400).json({
      ok: false,
      error: 'INVALID_INPUT',
      details: 'Los campos platform, email y password son requeridos.',
    });
  }

  const resolved = resolvePlatform(platform);
  if (!resolved) {
    return res.status(400).json({
      ok: false,
      error: 'UNSUPPORTED_PLATFORM',
      details: `Plataforma no soportada: ${platform}`,
    });
  }

  const { config, fileBase } = resolved;
  let browser = null;

  try {
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-blink-features=AutomationControlled',
      ],
    });

    const context = await browser.newContext({
      viewport: { width: 1366, height: 768 },
      locale: 'es-ES',
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    });

    const page = await context.newPage();
    page.setDefaultTimeout(20000);

    await page.goto(config.loginUrl, {
      waitUntil: 'domcontentloaded',
      timeout: 25000,
    });

    await page.waitForTimeout(1000);
    await dismissCookieBanners(page);

    // Optional pre-click (e.g., Apple TV+ "Sign In" button on landing page)
    if (Array.isArray(config.preClickSelectors) && config.preClickSelectors.length > 0) {
      const preBtn = await findVisibleLocator(page, config.preClickSelectors, 6000);
      if (preBtn) {
        await preBtn.locator.click();
        await page.waitForTimeout(1500);
      }
    }

    // Check if blocked by CAPTCHA before entering credentials
    const preIssue = await detectChallengeOrError(page);
    if (preIssue && preIssue.error === 'CAPTCHA_REQUIRED') {
      return res.status(422).json({ ok: false, ...preIssue });
    }

    // Step 1: Locate and fill email field
    const emailField = await findVisibleLocator(page, config.emailSelectors, 12000);
    if (!emailField) {
      const issue = await detectChallengeOrError(page);
      return res.status(422).json({
        ok: false,
        error: issue?.error || 'LOGIN_FORM_NOT_FOUND',
        details:
          issue?.details ||
          `No se encontró el formulario de inicio de sesión para ${config.name}.`,
      });
    }

    await emailField.locator.click();
    await emailField.locator.fill(String(email));

    // Adaptive 1-screen vs 2-screen handling:
    // Check if password input is already visible on the same screen (e.g. Netflix)
    let passwordField = await findVisibleLocator(page, config.passwordSelectors, 1200);

    if (!passwordField) {
      // 2-screen flow: click Continue / Submit email step
      const continueBtn = await findVisibleLocator(page, config.continueSelectors, 5000);
      if (continueBtn) {
        await continueBtn.locator.click();
      } else {
        await emailField.locator.press('Enter');
      }

      // Wait for password field OR challenge/error
      const step2Deadline = Date.now() + 12000;
      while (Date.now() < step2Deadline) {
        passwordField = await findVisibleLocator(page, config.passwordSelectors, 700);
        if (passwordField) break;

        const stepIssue = await detectChallengeOrError(page);
        if (stepIssue) {
          return res.status(422).json({ ok: false, ...stepIssue });
        }

        await page.waitForTimeout(300);
      }
    }

    if (!passwordField) {
      const issue = await detectChallengeOrError(page);
      return res.status(422).json({
        ok: false,
        error: issue?.error || 'PASSWORD_STEP_FAILED',
        details:
          issue?.details ||
          `No apareció el campo de contraseña tras ingresar el correo en ${config.name}.`,
      });
    }

    // Step 2: Fill password and submit
    await passwordField.locator.click();
    await passwordField.locator.fill(String(password));

    const submitBtn = await findVisibleLocator(page, config.submitSelectors, 5000);
    if (submitBtn) {
      await submitBtn.locator.click();
    } else {
      await passwordField.locator.press('Enter');
    }

    // Wait for successful login redirect or error/challenge
    const authDeadline = Date.now() + 18000;
    let authenticated = false;

    while (Date.now() < authDeadline) {
      await page.waitForTimeout(750);

      const currentUrl = page.url();
      const currentCookies = await context.cookies();

      if (isLoginSuccessful(currentUrl, currentCookies, config)) {
        authenticated = true;
        break;
      }

      const postIssue = await detectChallengeOrError(page);
      if (postIssue) {
        return res.status(422).json({ ok: false, ...postIssue });
      }
    }

    if (!authenticated) {
      const finalIssue = await detectChallengeOrError(page);
      return res.status(422).json({
        ok: false,
        error: finalIssue?.error || 'LOGIN_TIMEOUT',
        details:
          finalIssue?.details ||
          `No se confirmó la redirección de sesión exitosa en ${config.name} (URL actual: ${page.url()}).`,
      });
    }

    // Extract session cookies and save to /app/cookies/{platform}.json without deleting any other files
    const cookies = await context.cookies();
    await fs.mkdir(COOKIES_DIR, { recursive: true });

    const targetPath = path.resolve(COOKIES_DIR, `${fileBase}.json`);
    if (!targetPath.startsWith(path.resolve(COOKIES_DIR) + path.sep)) {
      return res.status(400).json({
        ok: false,
        error: 'INVALID_PLATFORM_PATH',
        details: 'Nombre de plataforma inválido para el archivo de cookies.',
      });
    }

    await fs.writeFile(targetPath, JSON.stringify(cookies, null, 2), 'utf8');

    return res.json({
      ok: true,
      file: targetPath,
      count: cookies.length,
    });
  } catch (err) {
    console.error(`[auth-worker] Error during login for ${platform}:`, err);
    return res.status(500).json({
      ok: false,
      error: 'AUTH_WORKER_ERROR',
      details: err?.message || 'Error inesperado durante la automatización de inicio de sesión.',
    });
  } finally {
    if (browser) {
      try {
        await browser.close();
      } catch (closeErr) {
        console.error('[auth-worker] Error closing browser:', closeErr);
      }
    }
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`[auth-worker] Listening on port ${PORT}`);
});
