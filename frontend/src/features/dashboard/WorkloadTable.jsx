import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { formatNumber } from '../../i18n/format';
import { NoData, Section } from './Section';

/**
 * The designers and what each has on the plate. The name is a link to the designer's tasks on the
 * board, and the whole row is a bigger click target for the same place.
 * @param {{workload: import('./api').Dashboard['workload']}} props
 */
export function WorkloadTable({ workload }) {
  const { t, i18n } = useTranslation('dashboard');
  const lng = /** @type {import('../../i18n/detect').Language} */ (i18n.language);
  const navigate = useNavigate();

  /** A click on the link itself is the link's business; anywhere else in the row opens the board. */
  function openRow(/** @type {import('react').MouseEvent} */ event, /** @type {string} */ to) {
    if (event.target instanceof Element && event.target.closest('a') !== null) {
      return;
    }
    void navigate(to);
  }

  return (
    <Section title={t('workload.title')} name="workload">
      {(headingId) =>
        workload.length === 0 ? (
          <NoData text={t('noData')} />
        ) : (
          <>
            <div className="dashboard-section__scroll">
              <table className="dashboard-table" aria-labelledby={headingId}>
                <thead>
                  <tr>
                    <th scope="col">{t('workload.designer')}</th>
                    <th scope="col" className="dashboard-table__number">
                      {t('workload.inProgress')}
                    </th>
                    <th scope="col" className="dashboard-table__number">
                      {t('workload.revisions')}
                    </th>
                    <th scope="col" className="dashboard-table__number">
                      {t('workload.inReview')}
                    </th>
                    <th scope="col" className="dashboard-table__number">
                      {t('workload.overdue')}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {workload.map((row) => {
                    const to = `/board?designerId=${row.designerId}`;
                    return (
                      <tr
                        key={row.designerId}
                        className="dashboard-table__row--link"
                        onClick={(event) => openRow(event, to)}
                      >
                        <td>
                          <Link to={to}>{row.name}</Link>
                        </td>
                        <td className="dashboard-table__number">
                          {formatNumber(row.inProgress, lng)}
                        </td>
                        <td className="dashboard-table__number">
                          {formatNumber(row.revisionsRequired, lng)}
                        </td>
                        <td className="dashboard-table__number">
                          {formatNumber(row.underReview, lng)}
                        </td>
                        <td
                          className="dashboard-table__number"
                          data-danger={row.overdue > 0 ? 'true' : undefined}
                        >
                          {formatNumber(row.overdue, lng)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            <p className="dashboard-section__note">{t('workload.hint')}</p>
          </>
        )
      }
    </Section>
  );
}
