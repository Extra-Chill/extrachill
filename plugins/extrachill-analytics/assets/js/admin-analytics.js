/**
 * Extra Chill Analytics Admin App
 *
 * @package ExtraChill\Analytics
 */

(function(wp) {
    const { render, createElement } = wp.element;

    const AnalyticsDashboard = () => {
        return createElement(
            'div',
            { className: 'extrachill-analytics-dashboard' },
            createElement('h2', null, 'Analytics Dashboard'),
            createElement('p', null, 'Welcome to the Extra Chill Analytics dashboard (v0.1.0). Data integration coming soon.')
        );
    };

    const container = document.getElementById('extrachill-analytics-app');
    if (container) {
        render(createElement(AnalyticsDashboard), container);
    }
})(window.wp);
