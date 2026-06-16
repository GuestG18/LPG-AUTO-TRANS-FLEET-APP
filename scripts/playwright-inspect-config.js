const fs = require('fs');
const path = require('path');

const playwrightModule = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = require(playwrightModule);

const baseUrl = (process.argv[2] || process.env.APP_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '');
const targetPath = process.argv[3] || '/index.php?page=dispecer_curse&action=config&beneficiar_edit_id=33';
const targetUrl = targetPath.startsWith('http') ? targetPath : `${baseUrl}${targetPath.startsWith('/') ? '' : '/'}${targetPath}`;
const screenshotDir = path.resolve(process.cwd(), 'playwright-artifacts');
const screenshotPath = path.join(screenshotDir, 'configurare-transport.png');
const routeScreenshotPath = path.join(screenshotDir, 'configurare-transport-rute.png');

async function isTextVisible(page, text) {
  return page.getByText(text, { exact: false }).first().isVisible().catch(() => false);
}

async function setCheckboxChecked(locator, checked) {
  await locator.evaluate((checkbox, value) => {
    checkbox.checked = value;
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
  }, checked);
}

(async () => {
  fs.mkdirSync(screenshotDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1834, height: 1055 } });

  await page.goto(`${baseUrl}/dev-login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('networkidle').catch(() => {});

  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForLoadState('networkidle').catch(() => {});

  const pageTitleVisible = await isTextVisible(page, 'Configurare transport');
  const distributionCheckbox = page.locator('input[type="checkbox"][name="tip_transporturi[]"][value="distributie"]');
  const primaryDistributionCheckbox = page.locator('input[type="checkbox"][name="tip_transporturi[]"][value="primar_distributie"]');
  const hasPrimaryDistributionOption = (await primaryDistributionCheckbox.count()) > 0;
  const canExerciseDistributionToggle = (await distributionCheckbox.count()) > 0 && await distributionCheckbox.isChecked();
  const canExercisePrimaryDistributionToggle = hasPrimaryDistributionOption && await primaryDistributionCheckbox.isChecked();
  let distributionMenuHidesWhenUnchecked = null;
  let distributionMenuShowsWhenChecked = null;
  let primaryDistributionMenuUnaffectedByDistribution = null;
  let primaryDistributionMenuHidesWhenUnchecked = null;
  let primaryDistributionMenuShowsWhenChecked = null;

  if (canExerciseDistributionToggle) {
    await setCheckboxChecked(distributionCheckbox, false);
    await page.waitForTimeout(100);
    distributionMenuHidesWhenUnchecked = !(await isTextVisible(page, 'Configuratii rute Distributie'));
    primaryDistributionMenuUnaffectedByDistribution = !canExercisePrimaryDistributionToggle
      || await isTextVisible(page, 'Configuratii rute Primar+Distributie');

    await setCheckboxChecked(distributionCheckbox, true);
    await page.waitForTimeout(100);
    distributionMenuShowsWhenChecked = await isTextVisible(page, 'Configuratii rute Distributie');
  }

  if (canExercisePrimaryDistributionToggle) {
    await setCheckboxChecked(primaryDistributionCheckbox, false);
    await page.waitForTimeout(100);
    primaryDistributionMenuHidesWhenUnchecked = !(await isTextVisible(page, 'Configuratii rute Primar+Distributie'));

    await setCheckboxChecked(primaryDistributionCheckbox, true);
    await page.waitForTimeout(100);
    primaryDistributionMenuShowsWhenChecked = await isTextVisible(page, 'Configuratii rute Primar+Distributie');
  }

  const distributionRouteCard = page.locator('[data-transport-card="distributie"]').first();
  const primaryDistributionRouteCard = page.locator('[data-transport-card="primar_distributie"]').first();
  const distributionRouteHasKmAgreati = await distributionRouteCard
    .getByText('Km agreati', { exact: false })
    .first()
    .isVisible()
    .catch(() => false);
  const distributionRouteHasCostCursa = await distributionRouteCard
    .getByText('Cost cursa', { exact: false })
    .first()
    .isVisible()
    .catch(() => false);
  const distributionRouteHasAplicaCostCursa = await distributionRouteCard
    .getByText(/Aplicare Cost Cursa|Aplica cost cursa/i)
    .first()
    .isVisible()
    .catch(() => false);
  const primaryDistributionRouteHasKmAgreati = await primaryDistributionRouteCard
    .getByText('Km agreati', { exact: false })
    .first()
    .isVisible()
    .catch(() => false);
  const primaryDistributionRouteHasCostCursa = await primaryDistributionRouteCard
    .getByText('Cost cursa', { exact: false })
    .first()
    .isVisible()
    .catch(() => false);
  const primaryDistributionRouteHasAplicaCostCursa = await primaryDistributionRouteCard
    .getByText(/Aplicare Cost Cursa|Aplica cost cursa/i)
    .first()
    .isVisible()
    .catch(() => false);
  const distributionTariffModeSelect = page.locator('#config_distribution_only_route_tarif_mod');
  const hasDistributionTariffModeSelect = (await distributionTariffModeSelect.count()) > 0;
  let distributionTariffModeBothEnablesBoth = null;
  let distributionTariffModeTonDisablesKm = null;
  let distributionTariffModeKmDisablesTon = null;
  if (hasDistributionTariffModeSelect) {
    const tonInput = page.locator('#config_distribution_only_route_tarif_tona');
    const kmInput = page.locator('#config_distribution_only_route_cost_extra_km');
    await distributionTariffModeSelect.selectOption('tona');
    await page.waitForTimeout(50);
    distributionTariffModeTonDisablesKm = !(await tonInput.isDisabled()) && await kmInput.isDisabled();
    await distributionTariffModeSelect.selectOption('km');
    await page.waitForTimeout(50);
    distributionTariffModeKmDisablesTon = await tonInput.isDisabled() && !(await kmInput.isDisabled());
    await distributionTariffModeSelect.selectOption('tona_km');
    await page.waitForTimeout(50);
    distributionTariffModeBothEnablesBoth = !(await tonInput.isDisabled()) && !(await kmInput.isDisabled());
  }

  const findings = {
    url: page.url(),
    pageTitleVisible,
    hasPrimaryDistributionOption,
    hiddenUselessDistributionTariffCard: !(await isTextVisible(page, 'Regula tarifare Distributie')),
    primaryTariffCardVisible: await isTextVisible(page, 'Regula tarifare Primar'),
    distributionRouteMenuVisible: await isTextVisible(page, 'Configuratii rute Distributie'),
    primaryDistributionRouteMenuVisible: await isTextVisible(page, 'Configuratii rute Primar+Distributie'),
    distributionMenuHidesWhenUnchecked,
    distributionMenuShowsWhenChecked,
    primaryDistributionMenuUnaffectedByDistribution,
    primaryDistributionMenuHidesWhenUnchecked,
    primaryDistributionMenuShowsWhenChecked,
    distributionRouteKmAgreatiRemoved: !distributionRouteHasKmAgreati,
    distributionRouteCostCursaRemoved: !distributionRouteHasCostCursa,
    distributionRouteAplicaCostCursaRemoved: !distributionRouteHasAplicaCostCursa,
    hasDistributionTariffModeSelect,
    distributionTariffModeBothEnablesBoth,
    distributionTariffModeTonDisablesKm,
    distributionTariffModeKmDisablesTon,
    primaryDistributionRouteKeepsKmAgreati: primaryDistributionRouteHasKmAgreati,
    primaryDistributionRouteKeepsCostCursa: primaryDistributionRouteHasCostCursa,
    primaryDistributionRouteKeepsAplicaCostCursa: primaryDistributionRouteHasAplicaCostCursa,
    screenshotPath,
    routeScreenshotPath,
  };

  await page.screenshot({ path: screenshotPath, fullPage: false });
  await page.getByText('Configuratii rute Distributie', { exact: false }).first().scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(100);
  await page.screenshot({ path: routeScreenshotPath, fullPage: false });
  await browser.close();

  console.log(JSON.stringify(findings, null, 2));
  if (
    !findings.pageTitleVisible
    || !findings.hasPrimaryDistributionOption
    || !findings.hiddenUselessDistributionTariffCard
    || findings.distributionMenuHidesWhenUnchecked === false
    || findings.distributionMenuShowsWhenChecked === false
    || findings.primaryDistributionMenuUnaffectedByDistribution === false
    || findings.primaryDistributionMenuHidesWhenUnchecked === false
    || findings.primaryDistributionMenuShowsWhenChecked === false
    || !findings.distributionRouteKmAgreatiRemoved
    || !findings.distributionRouteCostCursaRemoved
    || !findings.distributionRouteAplicaCostCursaRemoved
    || !findings.hasDistributionTariffModeSelect
    || !findings.distributionTariffModeBothEnablesBoth
    || !findings.distributionTariffModeTonDisablesKm
    || !findings.distributionTariffModeKmDisablesTon
    || !findings.primaryDistributionRouteKeepsKmAgreati
    || !findings.primaryDistributionRouteKeepsCostCursa
    || !findings.primaryDistributionRouteKeepsAplicaCostCursa
  ) {
    process.exit(1);
  }
})();
