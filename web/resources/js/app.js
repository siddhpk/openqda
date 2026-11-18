import './startup/theme.js';
import './startup/axios.js';
import './startup/errors.js';
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { setVocabulary } from './domain/qda/vocabulary.js';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy/dist/vue.m';

const appName =
  window.document.getElementsByTagName('title')[0]?.innerText || 'OpenQDA';

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) =>
    resolvePageComponent(
      `./Pages/${name}.vue`,
      import.meta.glob('./Pages/**/*.vue')
    ),
  setup({ el, App, props, plugin }) {
    // keep the project's analytic vocabulary in sync with the current page
    setVocabulary(props.initialPage?.props?.vocabulary);
    router.on('navigate', (event) =>
      setVocabulary(event.detail.page.props.vocabulary)
    );
    return createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(ZiggyVue, Ziggy)
      .mount(el);
  },
  progress: {
    color: '#88eeff',
  },
}).catch(console.error);
