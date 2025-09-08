import 'dotenv/config';
import express from 'express';
import cors from 'cors';
import OpenAI from 'openai';

const app = express();
app.use(cors());
app.use(express.json({ limit: '1mb' }));

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

/**
 * POST /api/rewrite
 * body: { text: string, imgDesc?: string }
 * returns: { hook, caption, alt, hashtags[] }
 */
app.post('/api/rewrite', async (req, res) => {
  try {
    const { text = '', imgDesc = '' } = req.body;

    const system = `
Doel: Corrigeer spelling/grammatica en herschrijf naar een algoritme-vriendelijke social-post.
Eisen:
- Korte krachtige hook (<=80 tekens) op 1e regel.
- Verbeter spelling en toon (spreektaal, duidelijk, actief).
- Voeg 1 call-to-action toe (bijv. "Volg voor meer", "Reageer hieronder").
- 3–7 relevante hashtags (geen spam).
- Als imgDesc bestaat: geef alt-tekst (<=120 tekens) die de inhoud beschrijft.
- Output in JSON: {"hook":"","caption":"","alt":"","hashtags":["#..."]}
- Wees feitelijk; geen ongepaste claims of copyrighted lyrics >10 woorden.
`;

    const user = `Tekst: """${text}""" \nAfbeelding: """${imgDesc}"""`;

    // OpenAI v4 SDK — JSON output
    const resp = await openai.chat.completions.create({
      model: 'gpt-4o-mini',
      temperature: 0.5,
      messages: [
        { role: 'system', content: system },
        { role: 'user', content: user }
      ],
      response_format: { type: 'json_object' }
    });

    const data = JSON.parse(resp.choices[0].message.content);

    // guards
    if (!Array.isArray(data.hashtags)) data.hashtags = [];
    if (!data.alt) data.alt = imgDesc?.slice(0, 120) || 'AI-geoptimaliseerde post';
    if (!data.hook) data.hook = (text || 'Nieuwe post').slice(0, 80);
    if (!data.caption) data.caption = text || '';

    res.json(data);
  } catch (e) {
    console.error('OpenAI error:', e?.response?.data || e.message || e);
    res.status(500).json({
      error: 'Rewrite failed',
      detail: e?.response?.data || e.message || String(e)
    });
  }
});

const PORT = process.env.PORT || 5174;
app.listen(PORT, () => console.log(`API running on http://127.0.0.1:${PORT}`));
app.get('/health', (req, res) => res.json({ ok: true }));
