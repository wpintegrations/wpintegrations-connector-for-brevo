import { useEffect, useState } from 'react'
import { Form, InputNumber, Input, Button, Divider, App as AntApp } from 'antd'
import { ApiOutlined, CheckCircleFilled, ArrowRightOutlined } from '@ant-design/icons'
import { api } from '../api'
import BrandLoader from '../components/BrandLoader'

// What the separate Pro plugin offers, as the readme lists it.
const PRO_FEATURES = [
  'Every Brevo contact attribute, including SMS, WhatsApp and custom attributes',
  'A delivery queue that retries a failed send on its own, and Resend in the log',
  'Conditions, such as sending only when the consent box is ticked',
  'Lists chosen by the visitor’s answers',
  'Brevo events, so automations can start from a form'
]

export default function Settings({ meta, onChanged }) {
  const { message } = AntApp.useApp()
  const [form] = Form.useForm()
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(null) // action key under test
  // The page waits on the payload, so the form takes it as initial values:
  // setFieldsValue would land on an unmounted form and be dropped.
  const [initial, setInitial] = useState(null)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    api
      .get('/settings')
      .then(setInitial)
      .catch(e => message.error(e.message))
      .finally(() => setReady(true))
  }, [message])

  const save = async values => {
    setSaving(true)
    try {
      const saved = await api.post('/settings', values)
      form.setFieldsValue(saved)
      message.success('Settings saved.')
      onChanged?.()
    } catch (e) {
      message.error(e.message)
    } finally {
      setSaving(false)
    }
  }

  // Tests the stored key, so unsaved edits are saved first.
  const testConnection = async actionKey => {
    setTesting(actionKey)
    try {
      if (form.isFieldsTouched()) {
        const saved = await api.post('/settings', form.getFieldsValue())
        form.setFieldsValue(saved)
        onChanged?.()
      }
      const { message: text } = await api.post('/connection/test', { action_key: actionKey })
      message.success(text)
    } catch (e) {
      message.error(e.message)
    } finally {
      setTesting(null)
    }
  }

  const actionsWithGlobals = meta.actions.filter(a => a.global_fields?.length)

  if (!ready) {
    return <BrandLoader />
  }

  return (
    <div style={{ maxWidth: 560 }}>
      <Form form={form} layout="vertical" initialValues={initial || undefined} onFinish={save}>
        {actionsWithGlobals.map(action => (
          <div key={action.key}>
            <Divider plain>{action.label}</Divider>
            {action.global_fields.map(gf => (
              <Form.Item
                key={gf.key}
                name={['actions', action.key, gf.key]}
                label={gf.label}
                extra={
                  action.key === 'brevo' && gf.key === 'api_key' ? (
                    <>
                      Create one in{' '}
                      <a href="https://app.brevo.com/settings/keys/api" target="_blank" rel="noreferrer">
                        Brevo → SMTP &amp; API → API keys
                      </a>
                      . It is stored encrypted and only its last characters are shown.
                    </>
                  ) : undefined
                }
              >
                <Input autoComplete="off" spellCheck={false} />
              </Form.Item>
            ))}
            <Button
              icon={<ApiOutlined />}
              loading={testing === action.key}
              onClick={() => testConnection(action.key)}
              style={{ marginBottom: 24 }}
            >
              Test connection
            </Button>
          </div>
        ))}

        <Divider plain>Logs</Divider>
        <Form.Item name="log_retention_days" label="Keep delivery logs for (days)">
          <InputNumber min={1} max={365} />
        </Form.Item>

        <Button type="primary" htmlType="submit" loading={saving}>
          Save settings
        </Button>
      </Form>

      {meta.pro_url && (
        <div className="wpi-pro-card">
          <div className="wpi-pro-card__head">
            <span className="wpi-pro-card__badge">PRO</span>
            <span className="wpi-pro-card__title">Get more out of Brevo</span>
          </div>
          <p className="wpi-pro-card__lead">
            Send every field your forms collect, never lose a lead to a failed send, and start Brevo
            automations right from your forms.
          </p>
          <ul className="wpi-pro-card__list">
            {PRO_FEATURES.map(feature => (
              <li key={feature}>
                <CheckCircleFilled className="wpi-pro-card__check" />
                <span>{feature}</span>
              </li>
            ))}
          </ul>
          <div className="wpi-pro-card__foot">
            <a className="wpi-pro-card__cta" href={meta.pro_url} target="_blank" rel="noreferrer">
              Upgrade to Pro <ArrowRightOutlined />
            </a>
          </div>
        </div>
      )}
    </div>
  )
}
