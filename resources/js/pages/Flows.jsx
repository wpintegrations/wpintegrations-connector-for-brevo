import { useEffect, useState } from 'react'
import {
  Table,
  Button,
  Drawer,
  Form,
  Input,
  Select,
  Space,
  Tag,
  Steps,
  Dropdown,
  Flex,
  Radio,
  Alert,
  Checkbox,
  Switch,
  Empty,
  Typography,
  Tooltip,
  Card,
  App as AntApp
} from 'antd'
import {
  PlusOutlined,
  DeleteOutlined,
  EditOutlined,
  MinusCircleOutlined,
  ReloadOutlined,
  MoreOutlined,
  SearchOutlined
} from '@ant-design/icons'
import { api } from '../api'
import { formatDate } from '../format'
import BrandLoader from '../components/BrandLoader'

// Source sentinel: the row's typed value is sent verbatim.
const STATIC_SOURCE = '_static'

// Values resolved at delivery time instead of read from the form.
// Keep in sync with Action::smart_value() in PHP.
const SMART_SOURCES = [
  { value: '_smart_page_url', label: 'Page URL' },
  { value: '_smart_submitted_at', label: 'Submission date & time' },
  { value: '_smart_user_email', label: 'Logged-in user email' },
  { value: '_smart_user_name', label: 'Logged-in user name' },
  { value: '_smart_site_title', label: 'Site title' },
  { value: '_smart_site_url', label: 'Site URL' }
]

// Short blurbs for the destination step. Keep in sync with the action labels.
const ACTION_DESCRIPTIONS = {
  brevo: 'Creates or updates a Brevo contact from each submission, and adds it to the lists you choose.'
}

// Groups gated behind a boolean flow setting: offered only while the toggle is
// on. A group with no entry here is always written.
const GROUP_TOGGLES = {}

const GROUP_LABELS = {
  default: 'Contact attributes'
}

// Supported form plugins, named when none is active.
const FORM_PLUGINS = 'Contact Form 7, Elementor Pro, WPForms, Fluent Forms or Gravity Forms'

// What an empty option list means once the API key works: the account simply
// has nothing of that kind yet.
const EMPTY_OPTIONS = {
  lists: 'No lists in your Brevo account yet',
  doi_templates: 'No double opt-in template found in your Brevo account'
}

export default function Flows({ meta, onChanged, onGoToSettings }) {
  const { message, modal } = AntApp.useApp()
  const [flows, setFlows] = useState([])
  const [loading, setLoading] = useState(true)
  // First payload only: later reloads keep the page up and spin the table.
  const [ready, setReady] = useState(false)
  const [sources, setSources] = useState(null)
  const [refreshingForms, setRefreshingForms] = useState(false)
  const [editing, setEditing] = useState(null) // null = closed, {} = new, {...flow} = edit
  const [step, setStep] = useState(0)
  const [saving, setSaving] = useState(false)
  const [actionKey, setActionKey] = useState(meta.actions[0]?.key)
  const [optionMap, setOptionMap] = useState({}) // sourceKey(+pipeline) => {options, loading, fetched}
  const [selectedRowKeys, setSelectedRowKeys] = useState([])
  const [query, setQuery] = useState('')
  const [form] = Form.useForm()

  // Each integration listens to one form plugin; only active ones are offered.
  const availableTriggers = meta.triggers.filter(t => t.available)
  const triggerLabel = key => meta.triggers.find(t => t.key === key)?.label || key
  const triggerKey = Form.useWatch('trigger_key', form)
  const trigger = meta.triggers.find(t => t.key === triggerKey)

  const action = meta.actions.find(a => a.key === actionKey) || meta.actions[0]

  // Which record groups this flow writes: everything not gated by a toggle,
  // plus the gated ones whose toggle is on.
  const settings = Form.useWatch('settings', form) || {}
  const groupOn = group => !GROUP_TOGGLES[group] || !!settings[GROUP_TOGGLES[group]]
  const includedGroups = new Set(
    (action?.target_fields || []).map(t => t.group || 'default').filter(groupOn)
  )

  // The group a target belongs to, or null when the action does not declare
  // it and we cannot place it.
  const groupOfTarget = key => {
    const standard = (action?.target_fields || []).find(t => t.key === key)
    return standard ? standard.group || 'default' : null
  }

  // Required targets get pinned rows with a fixed target; the user only picks
  // the form field that feeds them.
  const allTargets = (action?.target_fields || []).filter(t => includedGroups.has(t.group || 'default'))

  const requiredTargetKeys = allTargets.filter(t => t.required).map(t => t.key)
  const requiredKeySet = new Set(requiredTargetKeys)

  // One row per target is all a mapping needs.
  const maxRows = allTargets.length

  const labelForTarget = key => allTargets.find(t => t.key === key)?.label || key

  // One row per required target, always first. Existing rows move up
  // rather than being duplicated.
  const withRequiredRows = map => {
    const rows = Array.isArray(map) ? map.filter(Boolean) : []
    const required = requiredTargetKeys.map(k => {
      const idx = rows.findIndex(r => r?.target === k)
      return idx === -1 ? { target: k } : rows.splice(idx, 1)[0]
    })
    return [...required, ...rows]
  }

  const actionLabel = key => meta.actions.find(a => a.key === key)?.label || key

  const load = () => {
    setLoading(true)
    api
      .get('/flows')
      .then(setFlows)
      .catch(e => message.error(e.message))
      .finally(() => {
        setLoading(false)
        setReady(true)
      })
  }
  useEffect(() => {
    load()
    api
      .get('/sources')
      .then(setSources)
      .catch(() => setSources({}))
  }, [])

  const refreshForms = () => {
    setRefreshingForms(true)
    api
      .get('/sources?refresh=1')
      .then(setSources)
      .catch(e => message.error(e.message))
      .finally(() => setRefreshingForms(false))
  }

  // Forms the chosen form plugin reports. Shapes seen in real submissions
  // fill the gaps: dynamic fields and ids the scan could not see.
  const formsFor = key => sources?.[key] || []
  const shapesFor = key => meta.recent_shapes.filter(s => s.trigger === key)
  const forms = formsFor(triggerKey)
  const shapes = shapesFor(triggerKey)

  const selectedSource = Form.useWatch('trigger_source', form)
  const selectedForm = forms.find(f => f.id === selectedSource)

  // A form can feed any number of integrations, so the picker never
  // disables or annotates options.
  const formOptions = [
    ...forms.map(f => ({
      value: f.id,
      label: f.name
    })),
    ...shapes
      .filter(s => !forms.some(f => f.id === s.source))
      .map(s => ({
        value: s.source,
        label: `${s.label || s.source} (recently seen)`
      }))
  ]
  if (selectedSource && !formOptions.some(o => o.value === selectedSource)) {
    formOptions.push({ value: selectedSource, label: selectedSource })
  }

  // Field ids a form sends: its scanned fields plus any seen in submissions.
  const fieldIdsOf = (key, formId) =>
    new Set([
      ...(formsFor(key).find(f => f.id === formId)?.fields || []).map(f => f.id),
      ...(shapesFor(key).find(s => s.source === formId)?.fields || [])
    ])

  // Switching form plugin or form leaves mapping rows pointing at fields the
  // new form does not have. Those rows keep their Brevo field and lose only
  // the form field; smart and static values work with any form, so they stay.
  const releaseStaleSources = (key, formId) => {
    const ids = formId ? fieldIdsOf(key, formId) : new Set()
    const rows = (form.getFieldValue('field_map') || []).filter(Boolean)
    let cleared = 0
    const next = rows.map(row => {
      const src = row?.source
      const readsForm = src && src !== STATIC_SOURCE && !String(src).startsWith('_smart_')
      if (!readsForm || ids.has(src)) {
        return row
      }
      cleared++
      const { source, ...rest } = row
      return rest
    })
    if (cleared) {
      form.setFieldsValue({ field_map: next })
      message.info(
        `${cleared} mapped form field${cleared > 1 ? 's are' : ' is'} not on this form. Pick ${
          cleared > 1 ? 'them' : 'it'
        } again under Map fields.`
      )
    }
  }

  // A form's name for the table, where each row has its own form plugin.
  const sourceName = (key, value) =>
    formsFor(key).find(f => f.id === value)?.name ||
    shapesFor(key).find(s => s.source === value)?.label ||
    value

  // A field seen only in a submission has no label. A part id such as
  // "names.last_name" reads as "Names — Last name"; other ids are left as the
  // form plugin named them.
  const shapeLabel = id =>
    String(id).includes('.')
      ? String(id)
          .split('.')
          .map(part => {
            const words = part.replace(/[_-]+/g, ' ').trim()
            return words.charAt(0).toUpperCase() + words.slice(1)
          })
          .join(' — ')
      : id

  // Fields offered in the mapping's left column for the chosen form.
  // With no form selected, fall back to the union of everything known.
  const knownFields = (() => {
    const shapeFields = source =>
      (shapes.find(s => s.source === source)?.fields || []).map(id => ({
        id,
        label: shapeLabel(id),
        type: 'text'
      }))

    if (selectedForm) {
      const ids = new Set(selectedForm.fields.map(f => f.id))
      return [...selectedForm.fields, ...shapeFields(selectedForm.id).filter(f => !ids.has(f.id))]
    }
    if (selectedSource) {
      return shapeFields(selectedSource)
    }
    const seen = new Set()
    const all = []
    forms.forEach(f =>
      f.fields.forEach(ff => {
        if (!seen.has(ff.id)) {
          seen.add(ff.id)
          all.push(ff)
        }
      })
    )
    shapes.forEach(s =>
      s.fields.forEach(id => {
        if (!seen.has(id)) {
          seen.add(id)
          all.push({ id, label: shapeLabel(id), type: 'text' })
        }
      })
    )
    return all
  })()

  // A field id is usually just a slug of its label ("Name" → "name"), so append
  // it only when it adds information: a hand-set key, or an ambiguous name.
  // Generated ids — Gravity Forms and WPForms numbers ("2", "1.3") and the
  // part ids of name and address fields ("names.first_name") — mean nothing
  // to the user, so they show only to tell two same-named fields apart.
  const fieldOptions = (() => {
    const slug = s =>
      String(s ?? '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '')
    const generated = id => /^[\d.]+$/.test(id) || String(id).includes('.')

    const named = knownFields.map(f => ({ field: f, name: f.label || f.id }))
    const seen = new Set()
    const ambiguous = new Set()
    named.forEach(({ name }) => (seen.has(name) ? ambiguous.add(name) : seen.add(name)))

    return named.map(({ field, name }) => ({
      value: field.id,
      label:
        ambiguous.has(name) || (!generated(field.id) && slug(name) !== slug(field.id))
          ? `${name} (${field.id})`
          : name
    }))
  })()

  // Whether the action's site-wide connection (the API key) is saved.
  const connectionFor = aKey => !!meta.actions.find(a => a.key === aKey)?.connected
  const anyConnected = meta.actions.some(a => connectionFor(a.key))

  const goToSettings = (title, content) => {
    modal.confirm({
      title,
      content,
      okText: 'Go to Settings',
      cancelText: 'Cancel',
      onOk: () => onGoToSettings?.()
    })
    return false
  }

  // Nothing connected means no destination worth opening the editor for, and
  // no active form plugin means nothing to listen to.
  const requireAnyConnection = () => {
    if (!availableTriggers.length) {
      modal.info({
        title: 'No supported form plugin is active',
        content: `Activate ${FORM_PLUGINS}, then create the integration.`
      })
      return false
    }
    return (
      anyConnected ||
      goToSettings(
        'Connect Brevo first',
        'Integrations deliver through your Brevo API key, and none is saved yet. Add it in Settings, then create the integration.'
      )
    )
  }

  // The destination is chosen inside the editor, so its own connection can
  // only be checked once it is known — on the way out of the first step.
  const requireConnection = aKey =>
    connectionFor(aKey) ||
    goToSettings(
      `Connect ${actionLabel(aKey)} first`,
      `${actionLabel(
        aKey
      )} delivers through its API key, and none is saved yet. Add it under Settings, then come back.`
    )

  const openEditor = flow => {
    const aKey = flow?.action_key || meta.actions[0]?.key
    setActionKey(aKey)
    setEditing(flow || {})
    setStep(0)
    setOptionMap({})

    const selected = meta.actions.find(a => a.key === aKey) || meta.actions[0]
    const nextSettings = { ...(flow?.settings || {}) }
    // A new flow starts from each setting's declared default.
    if (!flow) {
      ;(selected?.flow_fields || []).forEach(ff => {
        if (ff.default !== undefined && nextSettings[ff.key] === undefined) {
          nextSettings[ff.key] = ff.default
        }
      })
    }

    form.setFieldsValue(
      flow
        ? {
            title: flow.title,
            trigger_key: flow.trigger_key,
            // Legacy "all forms" flows ('') must pick a form when next edited.
            trigger_source: flow.trigger_source || undefined,
            // Stored as-is: actionKey only updates next render, so pinning the
            // required rows here would use the previous action's. The step-2
            // effect pins them once the mapping step opens.
            field_map: (flow.field_map || []).filter(Boolean),
            settings: nextSettings
          }
        : {
            title: '',
            // One active form plugin needs no choosing.
            trigger_key: availableTriggers.length === 1 ? availableTriggers[0].key : undefined,
            trigger_source: undefined,
            // Start with a row for the destination's first target (Brevo's
            // Email): the usual identifier, though no longer a required one.
            field_map: (selected?.target_fields || []).slice(0, 1).map(t => ({ target: t.key })),
            settings: nextSettings
          }
    )
  }

  const close = () => {
    setEditing(null)
    form.resetFields()
  }

  // Switching destination mid-edit resets options for the new action; the
  // mapping rows are rebuilt below when the record groups change.
  useEffect(() => {
    if (editing === null) {
      return
    }
    setOptionMap({})
  }, [actionKey])

  const nextFromDestination = () => {
    if (!meta.actions.length) {
      message.warning('No destination available.')
      return
    }
    if (!requireConnection(actionKey)) {
      return
    }
    setStep(1)
  }

  const next = async () => {
    try {
      await form.validateFields(['title'])
    } catch {
      return
    }
    if (!form.getFieldValue('trigger_key')) {
      message.warning('Pick the form plugin this integration listens to.')
      return
    }
    if (!form.getFieldValue('trigger_source')) {
      message.warning('Pick the form this integration listens to.')
      return
    }
    setStep(2)
  }

  // Mapping targets, grouped by the record they write. Required targets live
  // only in pinned rows, so editable rows never offer them.
  const selectableTargets = allTargets.filter(t => !requiredKeySet.has(t.key))
  // Pinned rows hold a required target, so their (disabled) select needs the
  // required keys in its options too or it would render the bare key.
  const pinnedTargetOptions = allTargets.map(t => ({ value: t.key, label: t.label }))
  const targetOptions = [...includedGroups].map(group => ({
    label: GROUP_LABELS[group] || group,
    options: selectableTargets
      .filter(t => (t.group || 'default') === group)
      .map(t => ({ value: t.key, label: t.label }))
  }))

  // Mapping sources: the form's fields, delivery-time values, and a static
  // literal. Free text still works for field ids the scanner hasn't seen.
  const sourceOptions = [
    { label: 'Form fields', options: fieldOptions },
    { label: 'Smart values', options: SMART_SOURCES },
    { label: 'Other', options: [{ value: STATIC_SOURCE, label: 'Static value…' }] }
  ]

  // Current rows, used to show the value input on static ones.
  const fieldMapRows = Form.useWatch('field_map', form) || []

  // When the record groups change, the mapping rows follow: rows for a group
  // this flow no longer writes are dropped (they would never be sent), and the
  // pinned required rows are rebuilt around whatever is required now.
  useEffect(() => {
    if (editing === null || step < 2) {
      return
    }
    const rows = (form.getFieldValue('field_map') || []).filter(Boolean)
    const kept = rows.filter(r => {
      const group = groupOfTarget(r?.target)
      return group === null || includedGroups.has(group)
    })
    form.setFieldsValue({ field_map: withRequiredRows(kept) })

    const dropped = rows.length - kept.length
    if (dropped > 0) {
      message.info(
        `Removed ${dropped} mapping${
          dropped > 1 ? 's' : ''
        } for records this integration no longer creates.`
      )
    }
    // The helpers are recreated every render; only these triggers matter.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, editing, actionKey])

  // Options for flow settings whose field carries an options_source, fetched
  // once per (action, source) per drawer session.
  const loadOptions = (sourceKey, ctxSettings) => {
    const cacheKey = `${actionKey}:${sourceKey}`
    const cached = optionMap[cacheKey]
    if (cached && (cached.loading || cached.fetched)) {
      return
    }
    setOptionMap(m => ({
      ...m,
      [cacheKey]: { options: cached?.options || [], loading: true, fetched: false }
    }))
    api
      .post('/connection/options', {
        action_key: actionKey,
        option_key: sourceKey,
        settings: ctxSettings || {}
      })
      .then(data =>
        setOptionMap(m => ({
          ...m,
          [cacheKey]: { options: data?.options || [], loading: false, fetched: true }
        }))
      )
      .catch(() =>
        setOptionMap(m => ({
          ...m,
          [cacheKey]: { options: [], loading: false, fetched: true }
        }))
      )
  }

  const visibleFlowFields = (action?.flow_fields || []).filter(ff => {
    if (!ff.depends_on) {
      return true
    }
    return Object.entries(ff.depends_on).every(([key, values]) =>
      Array.isArray(values) ? values.includes(settings[key]) : Boolean(settings[key])
    )
  })

  // Record toggles render above the field map, since they decide what it
  // offers; everything else sits below, in declared order. A toggle flagged
  // 'below' belongs with the settings it switches on (double opt-in).
  const isTopToggle = ff => ff.type === 'boolean' && !ff.below
  const groupToggleFields = visibleFlowFields.filter(isTopToggle)
  const detailFlowFields = visibleFlowFields.filter(ff => !isTopToggle(ff))

  // One Form.Item per flow setting.
  const renderFlowField = ff => {
    const extra = ff.help
    const label = ff.label

    // A toggle carries its own label, next to the box rather than above it.
    if (ff.type === 'boolean') {
      return (
        <Form.Item
          key={ff.key}
          name={['settings', ff.key]}
          valuePropName="checked"
          extra={extra}
          // Defaults are applied in openEditor(), so editing an older flow
          // never silently switches a record on.
          initialValue={false}
          style={ff.below ? undefined : { marginBottom: 8 }}
        >
          <Checkbox>{label}</Checkbox>
        </Form.Item>
      )
    }

    const rule = ff.required
      ? [
          {
            required: true,
            message: `Pick ${ff.label.toLowerCase()}.`
          }
        ]
      : []
    let control
    if (ff.type === 'select') {
      let options = ff.options || []
      let loading = false
      let fetched = false
      if (ff.options_source) {
        const source = optionMap[`${actionKey}:${ff.options_source}`]
        options = source?.options || []
        loading = !!source?.loading
        fetched = !!source?.fetched
      }
      control = (
        <Select
          options={options}
          mode={ff.multiple ? 'multiple' : undefined}
          allowClear={!!ff.multiple}
          showSearch={ff.options_source}
          optionFilterProp="label"
          loading={loading}
          placeholder={
            ff.options_source
              ? loading
                ? 'Loading…'
                : options.length || !fetched
                ? 'Choose…'
                : !connectionFor(actionKey)
                ? 'Add your Brevo API key in Settings first'
                : EMPTY_OPTIONS[ff.options_source] || 'Nothing found in your Brevo account'
              : undefined
          }
        />
      )
    } else {
      control = <Input />
    }
    return (
      <Form.Item
        key={ff.key}
        name={['settings', ff.key]}
        label={label}
        extra={extra}
        rules={rule}
        // A multi-select's empty state must be a list, not a string. A single
        // select starts unset so its placeholder shows.
        initialValue={ff.type === 'select' && ff.multiple ? [] : undefined}
      >
        {control}
      </Form.Item>
    )
  }

  const optionSourcesShown = visibleFlowFields
    .filter(ff => ff.options_source)
    .map(ff => ff.options_source)
    .join(',')

  useEffect(() => {
    if (editing === null || step < 2) {
      return
    }
    const ctxSettings = form.getFieldValue('settings') || {}
    visibleFlowFields.forEach(ff => {
      if (ff.options_source) {
        loadOptions(ff.options_source, ctxSettings)
      }
    })
    // loadOptions depends on optionMap, which is only read for its guard; the
    // cache key covers everything that changes what should be fetched. A
    // select revealed by a toggle (the double opt-in template) loads when it
    // appears.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step, editing, actionKey, optionSourcesShown])

  const save = async () => {
    let values
    try {
      values = await form.validateFields()
    } catch {
      return
    }

    if (!values.trigger_key || !values.trigger_source) {
      message.warning('Pick the form this integration listens to.')
      setStep(1)
      return
    }

    // A row counts only when fully filled in; static rows also need a value.
    const isComplete = r =>
      r?.source && r?.target && (r.source !== STATIC_SOURCE || (r.value || '').trim() !== '')

    const rows = (values.field_map || []).filter(Boolean)
    const mapped = rows.filter(isComplete)

    const missingRequired = requiredTargetKeys.filter(k => !mapped.some(m => m.target === k))
    if (missingRequired.length) {
      const labels = missingRequired.map(labelForTarget).join(', ')
      message.warning(`Map a form field to ${labels} — Brevo requires it.`)
      return
    }

    // Block half-filled rows rather than dropping them silently. Required
    // rows were already reported above with a clearer message.
    if (rows.some(r => !isComplete(r) && !requiredKeySet.has(r?.target))) {
      message.warning(
        'Complete or remove the empty mapping rows — each row needs a form field, a Brevo field, and a value on static rows.'
      )
      return
    }

    const settingsOut = { ...(values.settings || {}) }

    setSaving(true)
    try {
      const payload = {
        ...values,
        settings: settingsOut,
        field_map: mapped,
        action_key: actionKey
      }
      if (editing?.id) {
        await api.put(`/flows/${editing.id}`, payload)
      } else {
        await api.post('/flows', payload)
      }
      message.success('Integration saved.')
      close()
      load()
      onChanged?.()
    } catch (e) {
      message.error(e.message)
    } finally {
      setSaving(false)
    }
  }

  const deleteFlows = async ids => {
    const results = await Promise.allSettled(ids.map(id => api.del(`/flows/${id}`)))
    const failed = results.filter(r => r.status === 'rejected').length
    if (failed) {
      message.error(`${failed} of ${ids.length} integrations could not be deleted.`)
    } else {
      message.success(ids.length > 1 ? `${ids.length} integrations deleted.` : 'Integration deleted.')
    }
    setSelectedRowKeys(keys => keys.filter(k => !ids.includes(k)))
    load()
  }

  const confirmDelete = rows => {
    modal.confirm({
      title: rows.length > 1 ? `Delete ${rows.length} integrations?` : `Delete “${rows[0].title}”?`,
      content: 'Form submissions will no longer be sent to Brevo. This cannot be undone.',
      okText: 'Delete',
      okButtonProps: { danger: true },
      onOk: () => deleteFlows(rows.map(r => r.id))
    })
  }

  const q = query.trim().toLowerCase()
  const visibleFlows = q
    ? flows.filter(
        f =>
          (f.title || '').toLowerCase().includes(q) ||
          String(sourceName(f.trigger_key, f.trigger_source || ''))
            .toLowerCase()
            .includes(q) ||
          triggerLabel(f.trigger_key).toLowerCase().includes(q) ||
          actionLabel(f.action_key).toLowerCase().includes(q)
      )
    : flows

  const selectedRows = flows.filter(f => selectedRowKeys.includes(f.id))

  const columns = [
    {
      title: 'Name',
      dataIndex: 'title',
      // Flexible column: absorbs the remaining width.
      minWidth: 180,
      ellipsis: true,
      sorter: (a, b) => (a.title || '').localeCompare(b.title || '')
    },
    {
      title: 'Destination',
      dataIndex: 'action_key',
      width: 150,
      ellipsis: true,
      render: key => <Tag>{actionLabel(key)}</Tag>
    },
    {
      title: 'Form plugin',
      dataIndex: 'trigger_key',
      width: 160,
      ellipsis: true,
      render: key => <Tag>{triggerLabel(key)}</Tag>
    },
    {
      title: 'Form',
      dataIndex: 'trigger_source',
      width: 200,
      ellipsis: true,
      render: (s, f) =>
        s ? <Tag>{sourceName(f.trigger_key, s)}</Tag> : <Tag color="blue">All forms</Tag>
    },
    {
      title: 'Created at',
      dataIndex: 'created_at',
      width: 190,
      sorter: (a, b) => (a.created_at || '').localeCompare(b.created_at || ''),
      render: formatDate
    },
    {
      title: 'Updated at',
      dataIndex: 'updated_at',
      width: 190,
      sorter: (a, b) => (a.updated_at || '').localeCompare(b.updated_at || ''),
      render: formatDate
    },
    {
      title: 'Status',
      dataIndex: 'status',
      width: 110,
      filters: [
        { text: 'Enabled', value: 'active' },
        { text: 'Disabled', value: 'inactive' }
      ],
      onFilter: (v, f) => (v === 'active' ? f.status === 'active' : f.status !== 'active'),
      render: (s, f) => (
        <Switch
          checked={s === 'active'}
          checkedChildren="On"
          unCheckedChildren="Off"
          onChange={() =>
            api
              .post(`/flows/${f.id}/toggle`)
              .then(load)
              .catch(e => message.error(e.message))
          }
        />
      )
    },
    {
      title: 'Actions',
      key: 'actions',
      align: 'center',
      width: 90,
      render: (_, f) => (
        <Dropdown
          trigger={['hover']}
          placement="bottomRight"
          menu={{
            items: [
              {
                key: 'edit',
                icon: <EditOutlined />,
                label: 'Edit'
              },
              { type: 'divider' },
              {
                key: 'delete',
                icon: <DeleteOutlined />,
                label: 'Delete',
                danger: true
              }
            ],
            onClick: ({ key }) => (key === 'edit' ? openEditor(f) : confirmDelete([f]))
          }}
        >
          <Button type="text" icon={<MoreOutlined />} aria-label="Integration actions" />
        </Dropdown>
      )
    }
  ]

  const steps = [{ title: 'Destination' }, { title: 'Form' }, { title: 'Map fields' }]

  if (!ready) {
    return <BrandLoader />
  }

  return (
    <>
      {!availableTriggers.length && (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
          message="No supported form plugin is active"
          description={`Integrations listen to ${FORM_PLUGINS}. Activate one of them to create an integration.`}
        />
      )}

      {!anyConnected && (
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 16 }}
          message="Brevo is not connected"
          description="Form submissions cannot be delivered until you add your Brevo API key. Anything submitted before then is not delivered."
          action={
            <Button size="small" type="primary" onClick={() => onGoToSettings?.()}>
              Connect
            </Button>
          }
        />
      )}

      <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 16 }}>
        <Button
          type="primary"
          icon={<PlusOutlined />}
          onClick={() => requireAnyConnection() && openEditor(null)}
        >
          New integration
        </Button>
        <Input
          allowClear
          prefix={<SearchOutlined style={{ color: 'rgba(0,0,0,.45)' }} />}
          placeholder="Search integrations"
          value={query}
          onChange={e => setQuery(e.target.value)}
          style={{ width: 260 }}
        />
      </Flex>

      {selectedRowKeys.length > 0 && (
        <Space style={{ marginBottom: 12 }}>
          <Typography.Text strong>{selectedRowKeys.length} selected</Typography.Text>
          <Tooltip title="Delete selected">
            <Button
              type="text"
              danger
              size="small"
              icon={<DeleteOutlined />}
              aria-label="Delete selected"
              onClick={() => confirmDelete(selectedRows)}
            />
          </Tooltip>
        </Space>
      )}

      <Table
        rowKey="id"
        dataSource={visibleFlows}
        columns={columns}
        loading={loading}
        size="middle"
        scroll={{ x: 1240 }}
        rowSelection={{
          selectedRowKeys,
          onChange: setSelectedRowKeys,
          columnWidth: 48
        }}
        onRow={record => ({
          style: { cursor: 'pointer' },
          // The whole row opens the editor, except clicks on the switch,
          // checkbox or action menu.
          onClick: e => {
            if (
              e.target.closest(
                'button, a, .ant-switch, .ant-checkbox-wrapper, .ant-table-selection-column, .ant-dropdown'
              )
            ) {
              return
            }
            openEditor(record)
          }
        })}
        pagination={{
          defaultPageSize: 10,
          showSizeChanger: true,
          pageSizeOptions: [10, 20, 50],
          showTotal: (total, range) => `${range[0]}–${range[1]} of ${total}`
        }}
        locale={{
          emptyText: q ? (
            <Empty description={`No integrations match “${query.trim()}”.`} />
          ) : (
            <Empty description="No integrations yet — send your form submissions to Brevo in three quick steps.">
              <Button
                type="primary"
                icon={<PlusOutlined />}
                onClick={() => requireAnyConnection() && openEditor(null)}
              >
                Create your first integration
              </Button>
            </Empty>
          )
        }}
      />

      <Drawer
        title={editing?.id ? 'Edit integration' : 'New integration'}
        open={editing !== null}
        onClose={close}
        width={600}
        destroyOnClose
      >
        <Steps
          size="small"
          current={step}
          items={steps}
          style={{ marginBottom: 24 }}
          onChange={editing?.id ? setStep : undefined}
        />

        <Form form={form} layout="vertical" requiredMark={false}>
          {/* Step 1 — pick the destination. */}
          <div style={{ display: step === 0 ? 'block' : 'none' }}>
            <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
              {meta.actions.length > 1
                ? 'Where should submissions go? You can change this later.'
                : 'Where submissions go.'}
            </Typography.Paragraph>
            {meta.actions.length > 1 ? (
              <Radio.Group value={actionKey} onChange={e => setActionKey(e.target.value)}>
                <Flex vertical gap={8}>
                  {meta.actions.map(a => (
                    <Radio.Button
                      key={a.key}
                      value={a.key}
                      style={{
                        height: 'auto',
                        whiteSpace: 'normal',
                        padding: '10px 14px'
                      }}
                    >
                      <div>
                        <strong>{a.label}</strong>
                      </div>
                      <div style={{ fontSize: 12, color: '#646970' }}>
                        {ACTION_DESCRIPTIONS[a.key] || a.label}
                      </div>
                    </Radio.Button>
                  ))}
                </Flex>
              </Radio.Group>
            ) : (
              <Card size="small">
                <strong>{action?.label}</strong>
                <div style={{ fontSize: 12, color: '#646970' }}>
                  {ACTION_DESCRIPTIONS[action?.key] || action?.label}
                </div>
              </Card>
            )}
          </div>

          {/* Step 2 — pick the form. Steps stay mounted so values persist. */}
          <div style={{ display: step === 1 ? 'block' : 'none' }}>
            {trigger && !trigger.available && (
              <Alert
                type="warning"
                showIcon
                style={{ marginBottom: 16 }}
                message={`${trigger.label} is not active on this site, so this integration receives nothing until it is.`}
              />
            )}
            <Form.Item
              name="title"
              label="Integration name"
              rules={[
                {
                  required: true,
                  message: 'Give this integration a name.'
                }
              ]}
            >
              <Input placeholder="e.g. Contact form leads" />
            </Form.Item>
            <Form.Item name="trigger_key" label="Form plugin">
              <Select
                options={[
                  ...availableTriggers.map(t => ({ value: t.key, label: t.label })),
                  // An edited flow keeps showing its plugin while that is off.
                  ...(trigger && !trigger.available
                    ? [{ value: trigger.key, label: `${trigger.label} (not active)` }]
                    : [])
                ]}
                placeholder="Choose a form plugin"
                // Form ids only mean something within their plugin.
                onChange={key => {
                  form.setFieldsValue({ trigger_source: undefined })
                  releaseStaleSources(key, undefined)
                }}
              />
            </Form.Item>
            <Form.Item
              label="Form"
              extra={
                triggerKey === 'elementor'
                  ? 'Forms found on published pages, templates and popups. Just added the form? Save the page and hit refresh.'
                  : 'Just added the form? Save it and hit refresh.'
              }
            >
              <Space.Compact style={{ width: '100%' }}>
                <Form.Item name="trigger_source" noStyle>
                  <Select
                    options={formOptions}
                    showSearch
                    optionFilterProp="label"
                    disabled={!triggerKey}
                    placeholder={triggerKey ? 'Choose a form' : 'Choose a form plugin first'}
                    onChange={formId => releaseStaleSources(triggerKey, formId)}
                  />
                </Form.Item>
                <Tooltip title="Rescan forms">
                  <Button icon={<ReloadOutlined spin={refreshingForms} />} onClick={refreshForms} />
                </Tooltip>
              </Space.Compact>
            </Form.Item>
          </div>

          {/* Step 3 — field mapping + options */}
          <div style={{ display: step === 2 ? 'block' : 'none' }}>
            <Flex justify="space-between" align="center" gap={8} style={{ marginBottom: 12 }}>
              <Typography.Text type="secondary">
                Match your form fields to {action?.label} fields.
              </Typography.Text>
            </Flex>

            {groupToggleFields.length > 0 && (
              <div style={{ marginBottom: 16 }}>
                <Typography.Text strong style={{ display: 'block', marginBottom: 4 }}>
                  Every submission adds the person to your Brevo contacts.
                </Typography.Text>
                {groupToggleFields.map(renderFlowField)}
              </div>
            )}

            <Form.List name="field_map">
              {(fields, { add, remove }) => (
                <>
                  {fields.map(({ key, name }) => {
                    // Pinned rows have a fixed target and cannot be removed.
                    const pinned = requiredKeySet.has(fieldMapRows[name]?.target)
                    return (
                      <div key={key}>
                        <Space
                          style={{
                            display: 'flex',
                            marginBottom: 4
                          }}
                          align="baseline"
                        >
                          <Form.Item
                            name={[name, 'source']}
                            style={{ marginBottom: 4 }}
                            getValueProps={v => ({ value: v ? [v] : [] })}
                            getValueFromEvent={arr =>
                              Array.isArray(arr) && arr.length ? arr[arr.length - 1] : undefined
                            }
                          >
                            <Select
                              mode="tags"
                              style={{ width: 210 }}
                              placeholder="Form field"
                              options={sourceOptions}
                              optionFilterProp="label"
                            />
                          </Form.Item>
                          <span>→</span>
                          <Form.Item name={[name, 'target']} style={{ marginBottom: 4 }}>
                            <Select
                              style={{ width: 210 }}
                              placeholder={action?.label + ' field'}
                              disabled={pinned}
                              options={pinned ? pinnedTargetOptions : targetOptions}
                              optionFilterProp="label"
                            />
                          </Form.Item>
                          {pinned ? (
                            <MinusCircleOutlined style={{ visibility: 'hidden' }} />
                          ) : (
                            <MinusCircleOutlined onClick={() => remove(name)} />
                          )}
                        </Space>
                        {fieldMapRows[name]?.source === STATIC_SOURCE && (
                          <Form.Item name={[name, 'value']} style={{ margin: '0 0 8px' }}>
                            <Input placeholder="Value to send to Brevo" />
                          </Form.Item>
                        )}
                      </div>
                    )
                  })}
                  {fields.length < maxRows && (
                    <Button
                      type="dashed"
                      onClick={() => add()}
                      block
                      icon={<PlusOutlined />}
                      style={{ marginBottom: 24 }}
                    >
                      Add field
                    </Button>
                  )}
                </>
              )}
            </Form.List>

            {detailFlowFields.map(renderFlowField)}
          </div>
        </Form>

        <Space style={{ marginTop: 16 }}>
          <Button onClick={close}>Cancel</Button>
          {step > 0 && <Button onClick={() => setStep(step - 1)}>Back</Button>}
          {step === 0 && (
            <Button type="primary" onClick={nextFromDestination}>
              Next
            </Button>
          )}
          {step === 1 && (
            <Button type="primary" onClick={next}>
              Next
            </Button>
          )}
          {step === 2 && (
            <Button type="primary" loading={saving} onClick={save}>
              {editing?.id ? 'Save changes' : 'Finish & activate'}
            </Button>
          )}
        </Space>
      </Drawer>
    </>
  )
}
