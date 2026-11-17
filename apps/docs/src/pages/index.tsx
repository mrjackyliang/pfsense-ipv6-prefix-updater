import {
  BlogPreview,
  BrowserFrame,
  Canvas,
  Features,
  Showcase,
  Spotlight,
} from '@cbnventures/docusaurus-preset-nova/blocks';
import Head from '@docusaurus/Head';
import Link from '@docusaurus/Link';
import Translate, { translate } from '@docusaurus/Translate';
import configurationScopesOne from '@locale-docs/configure/_assets_/configuration-scopes-1.png';
import interfaceAndPrefixOne from '@locale-docs/configure/_assets_/interface-and-prefix-1.png';
import statusAndActionsTwo from '@locale-docs/operate/_assets_/status-and-actions-2.png';
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
            message: 'IPv6 Prefix Updater for pfSense - Safe Dynamic Prefix Updates',
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
                    <Link className="nova-cta-primary" to="/docs/installation/">
                      <Translate id="home.hero.primaryAction" description="Primary homepage call to action.">
                        Get Started
                      </Translate>
                    </Link>
                    <Link className="nova-cta-secondary" to="/docs/intro/">
                      <Translate id="home.hero.secondaryAction" description="Secondary homepage call to action.">
                        Learn More
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

        <Spotlight
          surface="alt"
          className={styles['shot-spotlight']}
          heading={translate({
            id: 'home.showcase.heading',
            message: 'Inside the pfSense WebGUI',
            description: 'Heading above the WebGUI screenshots on the front page.',
          })}
        >
          <Showcase
            tabsLabel={translate({
              id: 'home.showcase.tabsLabel',
              message: 'IPv6 Prefix Updater pages',
              description: 'Accessible label for the screenshot tabs on the front page.',
            })}
            items={[
              {
                label: translate({
                  id: 'home.showcase.settings.label',
                  message: 'Settings',
                  description: 'Front page screenshot tab for the Settings page.',
                }),
                heading: translate({
                  id: 'home.showcase.settings.heading',
                  message: 'Watch the interfaces that carry your prefix',
                  description: 'Front page heading for the Settings page screenshot.',
                }),
                description: translate({
                  id: 'home.showcase.settings.description',
                  message: 'Add a row for each interface whose IPv6 address comes from your ISP, enter the prefix length it delegates, and choose when pfSense checks for a change.',
                  description: 'Front page description for the Settings page screenshot.',
                }),
                link: {
                  label: translate({
                    id: 'home.showcase.settings.link',
                    message: 'Choose the Interface and Prefix',
                    description: 'Front page link from the Settings screenshot to its guide.',
                  }),
                  to: '/docs/configure/interface-and-prefix/',
                },
                content: (
                  <BrowserFrame
                    className={styles['shot-frame']}
                    title={translate({
                      id: 'home.showcase.settings.title',
                      message: 'pfSense.home.arpa - Package: Services: IPv6 Prefix Updater: Settings',
                      description: 'Browser tab title of the Settings screenshot on the front page, as the pfSense WebGUI shows it.',
                    })}
                    url="192.168.1.1/pkg_edit.php?xml=ipv6-prefix-updater.xml"
                    favicon="/images/pfsense-favicon.ico"
                  >
                    <img
                      src={interfaceAndPrefixOne}
                      alt={translate({
                        id: 'home.showcase.settings.alt',
                        message: 'IPv6 Prefix Updater Settings page with two monitored interfaces and their prefix lengths',
                        description: 'Alt text for the Settings page screenshot on the front page.',
                      })}
                      width={1024}
                      height={768}
                    />
                  </BrowserFrame>
                ),
              },
              {
                label: translate({
                  id: 'home.showcase.status.label',
                  message: 'Status',
                  description: 'Front page screenshot tab for the Status page.',
                }),
                heading: translate({
                  id: 'home.showcase.status.heading',
                  message: 'A run should never be a black box',
                  description: 'Front page heading for the Status page screenshot.',
                }),
                description: translate({
                  id: 'home.showcase.status.description',
                  message: 'The Status tab shows each interface\'s last prefix and address, what the last run changed, whether a backup was made, and which services reloaded.',
                  description: 'Front page description for the Status page screenshot.',
                }),
                link: {
                  label: translate({
                    id: 'home.showcase.status.link',
                    message: 'Read the Status Guide',
                    description: 'Front page link from the Status screenshot to its guide.',
                  }),
                  to: '/docs/operate/status-and-actions/',
                },
                content: (
                  <BrowserFrame
                    className={styles['shot-frame']}
                    title={translate({
                      id: 'home.showcase.status.title',
                      message: 'pfSense.home.arpa - Services: IPv6 Prefix Updater: Status',
                      description: 'Browser tab title of the Status screenshot on the front page, as the pfSense WebGUI shows it.',
                    })}
                    url="192.168.1.1/ipv6-prefix-updater-status.php"
                    favicon="/images/pfsense-favicon.ico"
                  >
                    <img
                      src={statusAndActionsTwo}
                      alt={translate({
                        id: 'home.showcase.status.alt',
                        message: 'IPv6 Prefix Updater Status page with the current state of each interface and the last run',
                        description: 'Alt text for the Status page screenshot on the front page.',
                      })}
                      width={1024}
                      height={768}
                    />
                  </BrowserFrame>
                ),
              },
              {
                label: translate({
                  id: 'home.showcase.fields.label',
                  message: 'Fields',
                  description: 'Front page screenshot tab for the Fields page.',
                }),
                heading: translate({
                  id: 'home.showcase.fields.heading',
                  message: 'You decide what changes',
                  description: 'Front page heading for the Fields page screenshot.',
                }),
                description: translate({
                  id: 'home.showcase.fields.description',
                  message: 'Pick which configuration fields follow the prefix, set exceptions, and review other packages\' settings before anything is written.',
                  description: 'Front page description for the Fields page screenshot.',
                }),
                link: {
                  label: translate({
                    id: 'home.showcase.fields.link',
                    message: 'Choose the Fields',
                    description: 'Front page link from the Fields screenshot to its guide.',
                  }),
                  to: '/docs/configure/configuration-scopes/',
                },
                content: (
                  <BrowserFrame
                    className={styles['shot-frame']}
                    title={translate({
                      id: 'home.showcase.fields.title',
                      message: 'pfSense.home.arpa - Services: IPv6 Prefix Updater: Fields',
                      description: 'Browser tab title of the Fields screenshot on the front page, as the pfSense WebGUI shows it.',
                    })}
                    url="192.168.1.1/ipv6-prefix-updater-fields.php"
                    favicon="/images/pfsense-favicon.ico"
                  >
                    <img
                      src={configurationScopesOne}
                      alt={translate({
                        id: 'home.showcase.fields.alt',
                        message: 'IPv6 Prefix Updater Fields page with static interfaces and aliases selected',
                        description: 'Alt text for the Fields page screenshot on the front page.',
                      })}
                      width={1024}
                      height={768}
                    />
                  </BrowserFrame>
                ),
              },
            ]}
          />
        </Spotlight>

        <BlogPreview
          heading={translate({
            id: 'home.blogPreview.heading',
            message: 'From the Blog',
            description: 'Heading above the homepage blog preview.',
          })}
          description={translate({
            id: 'home.blogPreview.description',
            message: 'Launches, milestones, and what\'s new in IPv6 Prefix Updater for pfSense.',
            description: 'Summary below the homepage blog preview heading.',
          })}
          auto={true}
          limit={3}
          sort="newest"
        />
      </main>
    </Layout>
  );
}

export default Home;
