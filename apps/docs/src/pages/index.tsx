import {
  BlogPreview,
  Canvas,
  Features,
  Spotlight,
  Stats,
} from '@cbnventures/docusaurus-preset-nova/blocks';
import Head from '@docusaurus/Head';
import Link from '@docusaurus/Link';
import Translate, { translate } from '@docusaurus/Translate';
import Layout from '@theme/Layout';

import styles from './index.module.css';

import type { Pages_Index_Home_Returns } from '../types/pages/index.d.ts';

/**
 * Pages - Home.
 *
 * Introduces the package through its observable safety contract, a concrete
 * prefix transition, direct routes into task-oriented documentation, and the
 * latest blog posts.
 *
 * @returns {Pages_Index_Home_Returns}
 *
 * @since 0.0.0
 */
function Home(): Pages_Index_Home_Returns {
  return (
    <Layout description={translate({
      id: 'home.meta.description',
      message: 'Keep local IPv6 addresses in pfSense working when your ISP changes the delegated prefix.',
      description: 'Homepage metadata description.',
    })}
    >
      <Head>
        <title>
          {translate({
            id: 'home.meta.title',
            message: 'pfSense IPv6 Prefix Updater - Safe Dynamic Prefix Updates',
            description: 'Homepage browser title.',
          })}
        </title>
      </Head>

      <main>
        <Canvas container="full" surface="default" className={styles['hero-section']}>
          <div className={styles['hero']}>
            <div className={styles['hero-inner']}>
              <div className={styles['hero-grid']}>
                <div className={styles['hero-content']}>
                  <p className="nova-hero-eyebrow">
                    <Translate id="home.hero.eyebrow" description="Short label above the homepage headline.">
                      Native pfSense package
                    </Translate>
                  </p>
                  <h1 className="nova-hero-heading">
                    <Translate id="home.hero.heading" description="Homepage headline about safe dynamic IPv6 prefix updates.">
                      Keep dynamic IPv6 prefixes aligned safely.
                    </Translate>
                  </h1>
                  <p className="nova-hero-tagline">
                    <Translate id="home.hero.tagline" description="Homepage summary of package behavior.">
                      Keep local IPv6 addresses in pfSense working when your ISP changes the delegated prefix.
                    </Translate>
                  </p>
                  <div className="nova-hero-actions">
                    <Link className="nova-cta-primary" to="/docs/installation">
                      <Translate id="home.hero.primaryAction" description="Primary homepage call to action.">
                        Install on pfSense
                      </Translate>
                    </Link>
                    <Link className="nova-cta-secondary" to="/docs/operations">
                      <Translate id="home.hero.secondaryAction" description="Secondary homepage call to action.">
                        Review safety behavior
                      </Translate>
                    </Link>
                  </div>
                </div>

                <div
                  className={styles['transition-preview']}
                  role="group"
                  aria-label={translate({
                    id: 'home.preview.ariaLabel',
                    message: 'Example /56 prefix transition that preserves host bits',
                    description: 'Accessible label for the homepage prefix transition preview.',
                  })}
                >
                  <div className={styles['transition-header']}>
                    <span className={styles['transition-dot']} />
                    <span className={styles['transition-dot']} />
                    <span className={styles['transition-dot']} />
                    <span className={styles['transition-title']}>Dry Run Preview</span>
                    <span className={styles['transition-badge']}>
                      <Translate id="home.preview.dryRun" description="Dry-run badge in the prefix transition preview.">
                        Dry run
                      </Translate>
                    </span>
                  </div>
                  <div className={styles['transition-body']}>
                    <div className={styles['transition-row']}>
                      <span className={styles['transition-label']}>
                        <Translate id="home.preview.detected" description="Detected source-address label.">
                          Detected source
                        </Translate>
                      </span>
                      <code>2001:db8:abcd:88aa::1</code>
                    </div>
                    <div className={styles['transition-row']}>
                      <span className={styles['transition-label']}>
                        <Translate id="home.preview.previous" description="Previous IPv6 address label.">
                          Previous address
                        </Translate>
                      </span>
                      <code>
                        <span className={styles['prefix-old']}>2001:db8:abcd:78</span>
                        <span className={styles['host-bits']}>aa::42</span>
                      </code>
                    </div>
                    <div className={styles['transition-arrow']} aria-hidden="true">↓</div>
                    <div className={styles['transition-row']}>
                      <span className={styles['transition-label']}>
                        <Translate id="home.preview.current" description="Current IPv6 address label.">
                          Planned address
                        </Translate>
                      </span>
                      <code>
                        <span className={styles['prefix-new']}>2001:db8:abcd:88</span>
                        <span className={styles['host-bits']}>aa::42</span>
                      </code>
                    </div>
                    <div className={styles['transition-legend']}>
                      <span>
                        <i className={styles['legend-prefix']} />
                        <Translate id="home.preview.prefixChanged" description="Changed-prefix legend label.">
                          Prefix changed
                        </Translate>
                      </span>
                      <span>
                        <i className={styles['legend-host']} />
                        <Translate id="home.preview.hostPreserved" description="Preserved-host-bits legend label.">
                          Host bits preserved
                        </Translate>
                      </span>
                    </div>
                  </div>
                  <div className={styles['transition-result']}>
                    <span>✓</span>
                    <Translate id="home.preview.result" description="Result shown below the prefix transition preview.">
                      2 approved fields planned · configuration unchanged
                    </Translate>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </Canvas>

        <Canvas container="contained" surface="default" className={styles['workflow-section']}>
          <div className={styles['section-heading']}>
            <p className={styles['section-eyebrow']}>
              <Translate id="home.workflow.eyebrow" description="Short label above the update workflow.">
                What pfSense does
              </Translate>
            </p>
            <h2>
              <Translate id="home.workflow.heading" description="Heading for the update workflow.">
                Follow every prefix change from detection to completion.
              </Translate>
            </h2>
          </div>
          <ol className={styles['workflow-list']}>
            <li>
              <span>01</span>
              <Translate id="home.workflow.detect" description="Detect stage of the update workflow.">
                Detect new prefix
              </Translate>
            </li>
            <li>
              <span>02</span>
              <Translate id="home.workflow.plan" description="Plan stage of the update workflow.">
                Find local addresses
              </Translate>
            </li>
            <li>
              <span>03</span>
              <Translate id="home.workflow.backup" description="Backup stage of the update workflow.">
                Save a backup
              </Translate>
            </li>
            <li>
              <span>04</span>
              <Translate id="home.workflow.write" description="Write stage of the update workflow.">
                Update addresses
              </Translate>
            </li>
            <li>
              <span>05</span>
              <Translate id="home.workflow.verify" description="Verify stage of the update workflow.">
                Refresh services
              </Translate>
            </li>
            <li>
              <span>06</span>
              <Translate id="home.workflow.notify" description="Notification stage of the update workflow.">
                Report the result
              </Translate>
            </li>
          </ol>
        </Canvas>

        <Features
          items={[
            {
              icon: 'lucide:scan-search',
              title: translate({
                id: 'home.features.interface.title',
                message: 'Choose the Monitored Interfaces',
                description: 'Feature title for monitored-interface selection.',
              }),
              description: translate({
                id: 'home.features.interface.description',
                message: 'Add a row for each pfSense interface whose public IPv6 address comes from an ISP delegation. Status shows the exact address in use for each one.',
                description: 'Feature summary for monitored-interface selection.',
              }),
            },
            {
              icon: 'lucide:binary',
              title: translate({
                id: 'home.features.prefix.title',
                message: 'Enter the ISP Prefix Length',
                description: 'Feature title for delegated-prefix length.',
              }),
              description: translate({
                id: 'home.features.prefix.description',
                message: 'Enter the /48 through /64 prefix length supplied by the ISP. The device-specific portion of each address remains unchanged.',
                description: 'Feature summary for delegated-prefix calculation.',
              }),
            },
            {
              icon: 'lucide:list-checks',
              title: translate({
                id: 'home.features.scopes.title',
                message: 'Choose What Can Change',
                description: 'Feature title for field selection.',
              }),
              description: translate({
                id: 'home.features.scopes.description',
                message: 'Review the fields that follow the prefix on the Fields tab. pfSense groups start on, while settings from other packages, remote networks, peers, and secrets wait until you tick them.',
                description: 'Feature summary for field selection.',
              }),
            },
            {
              icon: 'lucide:search-code',
              title: translate({
                id: 'home.features.dryRun.title',
                message: 'Preview Without Changes',
                description: 'Feature title for dry-run behavior.',
              }),
              description: translate({
                id: 'home.features.dryRun.description',
                message: 'Dry Run shows the source address, old and new prefixes, affected-field count, and services without changing the firewall.',
                description: 'Feature summary for dry-run behavior.',
              }),
            },
            {
              icon: 'lucide:archive-restore',
              title: translate({
                id: 'home.features.backup.title',
                message: 'Back Up Before Changes',
                description: 'Feature title for backup ordering.',
              }),
              description: translate({
                id: 'home.features.backup.description',
                message: 'A real change creates one native pfSense configuration-history backup before any address is updated.',
                description: 'Feature summary for backup ordering.',
              }),
            },
            {
              icon: 'lucide:badge-check',
              title: translate({
                id: 'home.features.notify.title',
                message: 'Notify After Success',
                description: 'Feature title for verified notifications.',
              }),
              description: translate({
                id: 'home.features.notify.description',
                message: 'Notifications are sent only after an update is checked. Routine checks stay quiet when the prefix has not changed.',
                description: 'Feature summary for verified notifications.',
              }),
            },
          ]}
        />

        <Stats
          heading={translate({
            id: 'home.stats.heading',
            message: 'Safety You Can Verify',
            description: 'Heading above homepage safety metrics.',
          })}
          items={[
            {
              value: '/48–/64',
              label: translate({
                id: 'home.stats.boundaries',
                message: 'Supported prefix boundaries',
                description: 'Label for supported prefix-boundary metric.',
              }),
              color: 'primary',
            },
            {
              value: '1',
              label: translate({
                id: 'home.stats.backups',
                message: 'Backup before each change',
                description: 'Label for backup-count metric.',
              }),
              color: 'accent',
            },
            {
              value: '1',
              label: translate({
                id: 'home.stats.writes',
                message: 'Configuration write per change',
                description: 'Label for configuration-write metric.',
              }),
              color: 'primary',
            },
            {
              value: '0',
              label: translate({
                id: 'home.stats.noChange',
                message: 'Writes when the prefix is unchanged',
                description: 'Label for no-change write metric.',
              }),
              color: 'accent',
            },
          ]}
        />

        <Canvas container="contained" surface="alt" className={styles['routes-section']}>
          <div className={styles['section-heading']}>
            <p className={styles['section-eyebrow']}>
              <Translate id="home.routes.eyebrow" description="Short label above documentation routes.">
                Pick up where you are
              </Translate>
            </p>
            <h2>
              <Translate id="home.routes.heading" description="Heading above task-oriented documentation routes.">
                Follow the route for your next task.
              </Translate>
            </h2>
          </div>
          <div className={styles['route-grid']}>
            <Link className={styles['route-card'] ?? ''} to="/docs/installation">
              <span className={styles['route-icon']} aria-hidden="true">01</span>
              <h3>
                <Translate id="home.routes.install.title" description="Installation route title.">
                  Install the Package
                </Translate>
              </h3>
              <p>
                <Translate id="home.routes.install.description" description="Installation route summary.">
                  Install the latest GitHub release with one pfSense shell command, then confirm the package in the WebGUI.
                </Translate>
              </p>
              <span className={styles['route-link']}>
                <Translate id="home.routes.open" description="Label used to open a documentation route.">
                  Open guide
                </Translate>
                {' →'}
              </span>
            </Link>
            <Link className={styles['route-card'] ?? ''} to="/docs/configure/interface-and-prefix">
              <span className={styles['route-icon']} aria-hidden="true">02</span>
              <h3>
                <Translate id="home.routes.configure.title" description="Configuration route title.">
                  Select the Interface and Prefix
                </Translate>
              </h3>
              <p>
                <Translate id="home.routes.configure.description" description="Configuration route summary.">
                  Confirm which interface owns the global address and enter the delegated boundary supplied by the ISP.
                </Translate>
              </p>
              <span className={styles['route-link']}>
                <Translate id="home.routes.open" description="Label used to open a documentation route.">
                  Open guide
                </Translate>
                {' →'}
              </span>
            </Link>
            <Link className={styles['route-card'] ?? ''} to="/docs/operate/status-and-actions">
              <span className={styles['route-icon']} aria-hidden="true">03</span>
              <h3>
                <Translate id="home.routes.status.title" description="Status route title.">
                  Read Status and Command Output
                </Translate>
              </h3>
              <p>
                <Translate id="home.routes.status.description" description="Status route summary.">
                  Verify the chosen address, masked prefix, result, backup state, changed fields, and reloaded services after every run.
                </Translate>
              </p>
              <span className={styles['route-link']}>
                <Translate id="home.routes.open" description="Label used to open a documentation route.">
                  Open guide
                </Translate>
                {' →'}
              </span>
            </Link>
            <Link className={styles['route-card'] ?? ''} to="/docs/operate/troubleshooting">
              <span className={styles['route-icon']} aria-hidden="true">04</span>
              <h3>
                <Translate id="home.routes.troubleshoot.title" description="Troubleshooting route title.">
                  Troubleshoot a Run
                </Translate>
              </h3>
              <p>
                <Translate id="home.routes.troubleshoot.description" description="Troubleshooting route summary.">
                  Resolve wrong source addresses, zero-match warnings, failed safety stages, and recovery from pfSense configuration history.
                </Translate>
              </p>
              <span className={styles['route-link']}>
                <Translate id="home.routes.open" description="Label used to open a documentation route.">
                  Open guide
                </Translate>
                {' →'}
              </span>
            </Link>
          </div>
        </Canvas>

        <Spotlight
          heading={translate({
            id: 'home.spotlight.heading',
            message: 'A run should never be a black box.',
            description: 'Heading above the final homepage call to action.',
          })}
        >
          <div className={styles['spotlight-content']}>
            <p className="nova-section-description">
              <Translate id="home.spotlight.description" description="Summary of observable run evidence.">
                The Status tab and read-only command output show the exact source address, old and new prefixes, field count, backup result, service reloads, and final outcome.
              </Translate>
            </p>
            <div className={styles['spotlight-actions']}>
              <Link className="nova-cta-primary" to="/docs/operate/status-and-actions">
                <Translate id="home.spotlight.statusAction" description="Call to action for the status guide.">
                  Read the Status Guide
                </Translate>
              </Link>
              <Link className="nova-cta-secondary" to="/docs/safety/testing-and-verification">
                <Translate id="home.spotlight.testsAction" description="Call to action for the pre-automation checklist.">
                  Check Before Automation
                </Translate>
              </Link>
            </div>
          </div>
        </Spotlight>

        <BlogPreview
          heading={translate({
            id: 'home.blogPreview.heading',
            message: 'From the Blog',
            description: 'Heading above the homepage blog preview.',
          })}
          description={translate({
            id: 'home.blogPreview.description',
            message: 'Release notes, setup guides, and notes on dynamic IPv6 prefixes.',
            description: 'Summary below the homepage blog preview heading.',
          })}
          auto={true}
          limit={3}
        />
      </main>
    </Layout>
  );
}

export default Home;
