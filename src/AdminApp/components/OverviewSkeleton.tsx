import { __ } from '@wordpress/i18n';
import { Card } from '../design-system/Card';
import { Skeleton } from '../design-system/Skeleton';

export function OverviewSkeleton() {
  return (
    <div aria-busy="true" aria-label={__('Loading Fraud Guard overview', 'veridis-fraud-guard')}>
      <span className="vfg-sr-only" role="status">{__('Loading overview…', 'veridis-fraud-guard')}</span>
      <div className="vfg-page-heading vfg-page-heading--skeleton">
        <div>
          <Skeleton style={{ width: 120, height: 10 }} />
          <Skeleton style={{ width: 310, maxWidth: '70vw', height: 28, marginTop: 10 }} />
          <Skeleton style={{ width: 510, maxWidth: '82vw', height: 13, marginTop: 10 }} />
        </div>
      </div>
      <div className="vfg-kpi-strip vfg-kpi-strip--skeleton">
        {Array.from({ length: 4 }, (_, index) => (
          <div className="vfg-kpi" key={index}>
            <Skeleton style={{ width: 36, height: 36, flex: '0 0 36px' }} />
            <div className="vfg-kpi-skeleton-copy">
              <Skeleton style={{ width: '68%', height: 11 }} />
              <div className="vfg-kpi-value">
                <Skeleton style={{ width: 34, height: 24 }} />
                <Skeleton style={{ width: 76, height: 10 }} />
              </div>
            </div>
          </div>
        ))}
      </div>
      <div className="vfg-dashboard-layout">
        <div className="vfg-dashboard-main">
          <Card className="vfg-status-card">
            <div className="vfg-today-status vfg-today-status--skeleton">
              <Skeleton style={{ width: 42, height: 42 }} />
              <div>
                <Skeleton style={{ width: 104, height: 10 }} />
                <Skeleton style={{ width: 220, maxWidth: '75%', height: 17, marginTop: 6 }} />
                <Skeleton style={{ width: 360, maxWidth: '90%', height: 11, marginTop: 7 }} />
              </div>
              <Skeleton style={{ width: 52, height: 22 }} />
            </div>
          </Card>
          <Card
            className="vfg-recent-card"
            title={<Skeleton style={{ width: 230, maxWidth: '52vw', height: 16 }} />}
            action={<Skeleton style={{ width: 74, height: 11 }} />}
          >
            <div className="vfg-recent-empty">
              <Skeleton style={{ width: 40, height: 40, flex: '0 0 40px' }} />
              <div className="vfg-dashboard-empty-skeleton-copy">
                <Skeleton style={{ width: 260, maxWidth: '78%', height: 14 }} />
                <Skeleton style={{ width: 440, maxWidth: '94%', height: 11, marginTop: 7 }} />
              </div>
            </div>
          </Card>
        </div>
        <div className="vfg-dashboard-side">
          <Card
            title={<Skeleton style={{ width: 142, height: 16 }} />}
            action={<Skeleton style={{ width: 46, height: 11 }} />}
          >
            <div className="vfg-distribution-list vfg-distribution-list--skeleton">
              {Array.from({ length: 4 }, (_, index) => (
                <div className="vfg-distribution-row" key={index}>
                  <div className="vfg-distribution-meta">
                    <span><Skeleton style={{ width: 22, height: 22 }} /><Skeleton style={{ width: 58, height: 12 }} /></span>
                    <Skeleton style={{ width: 18, height: 13 }} />
                  </div>
                  <Skeleton style={{ width: '100%', height: 6 }} />
                </div>
              ))}
            </div>
          </Card>
          <Card title={<Skeleton style={{ width: 92, height: 16 }} />}>
            <div className="vfg-quick-actions">
              <Skeleton style={{ width: '100%', height: 38 }} />
              <Skeleton style={{ width: '100%', height: 38 }} />
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}
