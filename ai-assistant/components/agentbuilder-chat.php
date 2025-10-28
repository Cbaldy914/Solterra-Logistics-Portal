<?php
/**
 * AgentBuilder ChatKit embed (global_admin only)
 * - Mounts the new Agent Builder workflow via ChatKit for testing
 * - Gated to signed-in global_admin users
 */

// Require active session and role
if (session_status() === PHP_SESSION_NONE) {
    session_name("logistics_session");
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    return; // not logged in → do not render
}

$user_role = $_SESSION['role'] ?? 'user';
if ($user_role !== 'global_admin') {
    return; // only render for global_admin
}

// Your AgentBuilder workflow ID (updated)
$agent_workflow_id = 'wf_68ee5cb4e8f481909a58d336a02d6ae506d8bfc069874f75';

// Try to resolve OpenAI API key for ChatKit (testing only)
$openai_api_key = '';

// Prefer a config helper if available (production config.php)
// Silently ignore if not present
@include_once dirname(__DIR__, 3) . '/config.php';
if (function_exists('getOpenAIApiKey')) {
    try { $openai_api_key = (string) getOpenAIApiKey(); } catch (Throwable $e) { /* ignore */ }
}

// Fallbacks
if ($openai_api_key === '' && function_exists('getenv')) {
    $openai_api_key = getenv('OPENAI_API_KEY') ?: '';
}
if ($openai_api_key === '' && defined('OPENAI_API_KEY')) {
    $openai_api_key = (string) OPENAI_API_KEY;
}

?>

<div id="agentbuilder-chatkit-root" style="position: fixed; bottom: 20px; right: 20px; z-index: 2147483647;
  width: 380px; max-width: calc(100vw - 32px); height: 600px; display: block; background: transparent;"></div>

<script>
(function(){
  // Expose minimal context to ChatKit if you want to pass user details
  window.AgentBuilderContext = {
    userId: <?php echo json_encode($_SESSION['user_id']); ?>,
    username: <?php echo json_encode($_SESSION['username'] ?? 'Admin'); ?>,
    role: <?php echo json_encode($user_role); ?>
  };
})();
</script>

<!-- ChatKit platform loader as recommended in docs -->
<script src="https://cdn.platform.openai.com/deployments/chatkit/chatkit.js" async></script>

<!-- Import map to guarantee a single React/ReactDOM instance for chatkit-react -->
<script type="importmap">
{
  "imports": {
    "react": "https://esm.sh/react@18",
    "react-dom": "https://esm.sh/react-dom@18",
    "react-dom/client": "https://esm.sh/react-dom@18/client",
    "react/jsx-runtime": "https://esm.sh/react@18/jsx-runtime",
    "react/jsx-dev-runtime": "https://esm.sh/react@18/jsx-dev-runtime"
  }
}
</script>

<!-- Minimal React mount using ESM CDN and chatkit-react bindings per docs -->
<script type="module">
(async function(){
  function getDeviceId(){
    try {
      const key = 'chatkit_device_id';
      let id = localStorage.getItem(key);
      if (!id) { id = 'dev_' + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem(key, id); }
      return id;
    } catch (_) { return 'dev_session'; }
  }

  const React = await import('react');
  const ReactDOM = await import('react-dom/client');
  // Force chatkit-react to use our React/DOM via externals to avoid duplicate React
  const ChatKitReact = await import('https://esm.sh/@openai/chatkit-react@latest?external=react,react-dom');

  const { createElement } = React;
  const { createRoot } = ReactDOM;
  const { ChatKit, useChatKit } = ChatKitReact;

  function ABChat(){
    const { control } = useChatKit({
      api: {
        async getClientSecret(existing){
          // If ChatKit passes an existing, reuse it to avoid creating a new session
          if (existing) return existing;
          const res = await fetch('./ai-assistant/api/chatkit-session.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ deviceId: getDeviceId() })
          });
          const data = await res.json();
          if (!res.ok || !data?.client_secret) throw new Error(data?.message || 'Failed to get client_secret');
          return data.client_secret;
        }
      }
    });
    return createElement(ChatKit, { control, className: 'ab-chatkit', style: { height: '100%', width: '100%' } });
  }

  const rootEl = document.getElementById('agentbuilder-chatkit-root');
  if (rootEl) {
    const root = createRoot(rootEl);
    root.render(createElement(ABChat));
  }
})();
</script>

<?php
// End of component
?>


