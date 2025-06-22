class SunnyChat {
  constructor() {
    this.container = document.getElementById('sunny-chat');
    if (!this.container) return;
    this.render();
  }

  render() {
    this.container.innerHTML = `
      <div class="sunny-box fixed bottom-4 right-4 w-80 bg-white shadow-lg rounded-lg flex flex-col text-sm">
        <div class="flex items-center justify-between bg-yellow-500 text-white p-2 rounded-t-lg">
          <span>Sunny</span>
          <button type="button" id="sunny-close" class="font-bold">&times;</button>
        </div>
        <div id="sunny-messages" class="p-2 space-y-2 overflow-y-auto" style="max-height:200px"></div>
        <form id="sunny-form" class="flex border-t p-2 gap-2">
          <input id="sunny-input" class="flex-1 border rounded px-2" placeholder="Ask me..." />
          <button class="bg-yellow-500 text-white px-3 rounded">Send</button>
        </form>
      </div>`;

    this.messagesEl = this.container.querySelector('#sunny-messages');
    this.form = this.container.querySelector('#sunny-form');
    this.input = this.container.querySelector('#sunny-input');
    this.container.querySelector('#sunny-close').addEventListener('click', () => {
      this.container.querySelector('.sunny-box').classList.toggle('hidden');
    });
    this.form.addEventListener('submit', e => {
      e.preventDefault();
      this.sendMessage(this.input.value.trim());
      this.input.value = '';
    });
  }

  addBubble(text, from) {
    const div = document.createElement('div');
    div.className = from === 'user' ? 'self-end bg-blue-100 p-2 rounded' : 'bg-gray-100 p-2 rounded';
    div.textContent = text;
    this.messagesEl.appendChild(div);
    this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    return div;
  }

  async sendMessage(msg) {
    if (!msg) return;
    this.addBubble(msg, 'user');
    const bubble = this.addBubble('', 'ai');
    const response = await fetch('/Solterra-Logistics-Portal/api/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: msg })
    });
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let lines = buffer.split('\n');
      buffer = lines.pop();
      for (const line of lines) {
        if (!line) continue;
        const chunk = JSON.parse(line);
        if (chunk.token) {
          bubble.textContent += chunk.token;
        }
        if (chunk.done) {
          bubble.textContent += '\n';
        }
      }
    }
  }
}

document.addEventListener('DOMContentLoaded', () => new SunnyChat());
