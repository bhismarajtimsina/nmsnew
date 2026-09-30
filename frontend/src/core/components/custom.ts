import app from '@/config/configApp';
import Cards from '@/components/cards/frame/CardsFrame.vue';
import Button from '@/components/buttons/Buttons.vue';
import CalendarButton from '../../components/buttons/CalendarButton.vue';
import ExportButton from '../../components/buttons/ExportButton.vue';
import ShareButton from '../../components/buttons/ShareButton.vue';
import Dropdown from '@/components/dropdown/Dropdown.vue';
import Heading from '@/components/heading/Heading.vue';
import Popover from '@/components/popup/Popup.vue';
import Modal from '@/components/modals/Modals.vue';
import AutoComplete from '@/components/autoComplete/autoComplete.vue';
import { PageHeader } from '@/components/pageHeaders/PageHeaders.vue';
import FeatherIcons from '@/components/utilities/featherIcons.vue';
import Alerts from '../../components/alerts/Alerts.vue';
import BtnGroup from '../../components/buttons/ButtonGroup.vue';
import Cascader from '../../components/cascader/Cascader.vue';
import Drawer from '../../components/drawer/Drawer.vue';

const components = [
  Cards,
  Heading,
  Dropdown,
  Popover,
  AutoComplete,
  Modal,
  FeatherIcons,
  { ...PageHeader, name: 'PageHeader' },
  {
    ...BtnGroup,
    name: 'BtnGroup',
  },
  { ...Button },
  Alerts,
  Cascader,
  Drawer,
  CalendarButton,
  ExportButton,
  ShareButton,
];

components.forEach((c) => {
  app.component(`sd${c.name}`, c);
});
