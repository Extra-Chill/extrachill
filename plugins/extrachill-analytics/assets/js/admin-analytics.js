/**
 * Extra Chill Analytics Admin App
 *
 * @package ExtraChill\Analytics
 */

document.addEventListener('DOMContentLoaded', () => {
    try {
        if (!window.wp?.element) {
            console.error('Extra Chill Analytics: wp.element not available');
            return;
        }

        const container = document.getElementById('extrachill-analytics-app');
        if (!container) {
            console.error('Extra Chill Analytics: Container not found');
            return;
        }

        const { createRoot, createElement } = window.wp.element;

        const AnalyticsDashboard = () => {
            return createElement(
                'div',
                { className: 'extrachill-analytics-dashboard' },
                createElement('h2', null, 'Analytics Dashboard'),
                createElement('p', null, 'Welcome to the Extra Chill Analytics dashboard (v0.1.0). Data integration coming soon.')
            );
        };

        const root = createRoot(container);
        root.render(createElement(AnalyticsDashboard));
    } catch (error) {
        console.error('Extra Chill Analytics: Failed to initialize dashboard:', error);
    }
});
